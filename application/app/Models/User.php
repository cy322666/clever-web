<?php

namespace App\Models;

use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Billing\SubscriptionInvoiceRequest;
use App\Models\Billing\WidgetSubscription;
use App\Models\Core\Account;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Rappasoft\LaravelAuthenticationLog\Traits\AuthenticationLoggable;

class User extends Authenticatable implements FilamentUser
{
    use AuthenticationLoggable, HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'uuid',
        'active',
        'count_inputs',
        'locale',
        'crm_provider',
        'industry',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
    ];

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'app' && (bool) $this->active;
    }

    public function needsOnboarding(): bool
    {
        return Schema::hasColumn($this->getTable(), 'onboarding_completed_at')
            && ! $this->onboarding_completed_at;
    }

    public function canImpersonate()
    {
        return $this->is_root;
    }

    public function canBeImpersonated(): bool
    {
        return ! $this->is_root;
    }

    public function tilda_settings(): HasOne
    {
        return $this->hasOne(\App\Models\Integrations\Tilda\Setting::class);
    }

    public function distribution_settings(): HasOne
    {
        return $this->hasOne(\App\Models\Integrations\Distribution\Setting::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(Account::class)
            ->where(function ($query) {
                $query->where('widget', Account::DEFAULT_WIDGET)
                    ->orWhereNull('widget');
            })
            ->latest('id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }

    public function widgetSubscriptions(): HasMany
    {
        return $this->hasMany(WidgetSubscription::class);
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(\App\Models\Workflows\Workflow::class);
    }

    public function workflowRuns(): HasMany
    {
        return $this->hasMany(\App\Models\Workflows\WorkflowRun::class);
    }

    public function workflowCredentials(): HasMany
    {
        return $this->hasMany(\App\Models\Workflows\WorkflowCredential::class);
    }

    public function subscriptionInvoiceRequests(): HasMany
    {
        return $this->hasMany(SubscriptionInvoiceRequest::class);
    }

    public function amocrm_staffs(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function amocrm_statuses(): HasMany
    {
        return $this->hasMany(Status::class);
    }

    public function amocrm_logs(): HasMany
    {
        return $this->hasMany(Log::class);
    }

    public function amocrm_fields(): HasMany// amocrm TODO
    {
        return $this->hasMany(Field::class)
            ->where('active', true);
    }

    public function amocrm_fields_contact(): HasMany
    {
        return $this->hasMany(Field::class)
            ->where('active', true)
            ->where('entity_type', 'contacts');
    }

    public function amocrm_fields_lead(): HasMany
    {
        return $this->hasMany(Field::class)
            ->where('active', true)
            ->where('entity_type', 'leads');
    }

    public function yclientsSetting(): HasOne
    {
        return $this->hasOne(\App\Models\Integrations\YClients\Setting::class);
    }

    public function vetmanagerSetting(): HasOne
    {
        return $this->hasOne(\App\Models\Integrations\Vetmanager\Setting::class);
    }

    public function sqnsSetting(): HasOne
    {
        return $this->hasOne(\App\Models\Integrations\Sqns\Setting::class);
    }

    public function resolveAmoAccountForWidget(?string $widget, bool $createIfMissing = false): ?Account
    {
        $widget = Account::normalizeWidget($widget);

        $specific = $this->accounts()
            ->where('widget', $widget)
            ->latest('id')
            ->first();

        if ($widget === 'import-excel') {
            if ($specific) {
                return $createIfMissing || \App\Services\ImportExcel\ExcelConnectionAccess::isWidgetAccount($specific)
                    ? $specific : null;
            }

            return $createIfMissing
                ? Account::query()->create(['user_id' => $this->id, 'widget' => $widget])
                : null;
        }

        if ($widget === 'sqns') {
            if ($specific) {
                if ($createIfMissing) {
                    \App\Services\Sqns\AmoCrmConnectionAccess::prepareForAuthorization($specific);

                    return $specific;
                }

                return \App\Services\Sqns\AmoCrmConnectionAccess::isWidgetAccount($specific)
                    ? $specific : null;
            }

            return $createIfMissing
                ? Account::query()->create(['user_id' => $this->id, 'widget' => $widget])
                : null;
        }

        // Marketplace installs must keep their own OAuth even after a reset.
        if ($widget === 'yclients' && $specific?->oauth_connector === Account::CONNECTOR_WIDGET) {
            return $specific;
        }

        $useSharedConnector = ($specific ?? new Account)->usesSharedConnectorForWidget($widget);
        $sharedClientId = $useSharedConnector ? (string) config('services.amocrm.client_id', '') : null;

        if ($specific && $this->amoAccountIsUsable($specific)
            && (! $useSharedConnector || $specific->client_id === $sharedClientId)) {
            return $specific;
        }

        // Preserve shared authorization for legacy integrations, but never
        // use the dedicated Excel connection as their shared connector.
        $shared = $this->resolveAnyActiveAmoAccount($sharedClientId);

        if ($shared instanceof Account) {
            return $shared;
        }

        if ($specific) {
            return $specific;
        }

        $default = $this->accounts()
            ->where(function ($query) {
                $query->where('widget', Account::DEFAULT_WIDGET)
                    ->orWhereNull('widget');
            })
            ->latest('id')
            ->first();

        if (! $createIfMissing || $widget === Account::DEFAULT_WIDGET) {
            return $default;
        }

        $payload = [
            'user_id' => $this->id,
            'widget' => $widget,
        ];

        if ($default) {
            $payload = array_merge($payload, [
                'subdomain' => $default->subdomain,
                'zone' => $default->zone,
            ]);
        }

        return Account::query()->create($payload);
    }

    public function usesSharedAmoConnectionAcrossWidgets(): bool
    {
        return true;
    }

    private function amoAccountIsUsable(Account $account): bool
    {
        return (bool) $account->active
            && filled($account->subdomain)
            && (filled($account->access_token) || filled($account->refresh_token));
    }

    private function resolveAnyActiveAmoAccount(?string $clientId = null): ?Account
    {
        return $this->accounts()
            ->where(fn ($query) => $query->whereNull('widget')
                ->orWhereNotIn('widget', Account::dedicatedConnectorWidgets()))
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->where('active', true)
            ->whereNotNull('subdomain')
            ->where('subdomain', '<>', '')
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->whereNotNull('access_token')
                        ->where('access_token', '<>', '');
                })->orWhere(function ($query): void {
                    $query->whereNotNull('refresh_token')
                        ->where('refresh_token', '<>', '');
                });
            })
            ->orderByDesc('id')
            ->first();
    }
}
