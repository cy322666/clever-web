<?php

namespace App\Models\Integrations\Finder;

use App\Filament\Resources\Integrations\Finder\FinderResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\App;
use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use SettingRelation;

    protected $table = 'finder_settings';

    protected $fillable = ['user_id', 'account_id', 'active', 'enabled', 'settings'];

    protected $casts = ['active' => 'boolean', 'enabled' => 'boolean', 'settings' => 'array', 'connected_at' => 'datetime', 'last_webhook_at' => 'datetime'];

    public static string $resource = FinderResource::class;

    public static array $cost = [
        '1_month' => '1 000 руб',
        '6_month' => '6 000 руб',
        '12_month' => '10 000 руб',
        '1_month_per_user' => '249 руб',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function amoAccount(bool $createIfMissing = false, ?string $fallbackWidget = null): ?Account
    {
        // Keep the connection selected at installation. Older settings can still
        // use the shared platform authorization until their own installation.
        $account = $this->account()->where('user_id', $this->user_id)->where('active', true)->first();
        if ($account && filled($account->subdomain) && (filled($account->access_token) || filled($account->refresh_token))) {
            return $account;
        }

        return $this->user?->resolveAmoAccountForWidget(Account::DEFAULT_WIDGET, false);
    }

    public function options(): array
    {
        return array_replace([
            'working_time' => false,
            'timezone' => 'Europe/Moscow',
            'schedule' => [['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '20:00']],
            'hours' => 0, 'minutes' => 5, 'max_attempts' => 3,
            'run_workflow' => false, 'workflow_id' => null,
            'create_task' => false, 'responsible_user_id' => null,
            'task_type_id' => 1, 'task_text' => 'Ответить клиенту', 'task_due_minutes' => 15,
            'run_reply_workflow' => false, 'reply_workflow_id' => null,
        ], $this->settings ?? []);
    }

    public function isMonitoringEnabled(): bool
    {
        return $this->active && $this->enabled && $this->app()->where('status', App::STATE_ACTIVE)->exists();
    }

    public function intervalSeconds(): int
    {
        $options = $this->options();

        return max(60, ((int) $options['hours'] * 60 + (int) $options['minutes']) * 60);
    }
}
