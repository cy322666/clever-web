<?php

namespace App\Models\Core;

use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    use HasFactory;

    public const DEFAULT_WIDGET = 'default';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'code',
        'zone',
        'state',
        'client_id',
        'work',
        'client_secret',
        'referer',
        'expires_in',
        'created_at',
        'token_type',
        'redirect_uri',
        'endpoint',
        'expires_tariff',
        'widget',
    ];

    protected $guarded = [];

    protected $casts = [
        'amo_account_id' => 'integer',
        'active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Account $account): void {
            // Check new bindings, not routine token refreshes of legacy connections.
            if (!filled($account->subdomain) || !$account->user_id
                || ($account->exists && !$account->isDirty(['subdomain', 'zone', 'user_id'])
                    && !($account->isDirty('active') && $account->active))) return;

            $other = static::query()->where('user_id', $account->user_id)
                ->when($account->exists, fn ($query) => $query->whereKeyNot($account->getKey()))
                ->whereNotNull('subdomain')->where('subdomain', '<>', '')
                ->get()->first(fn (Account $connected): bool =>
                    ($connected->active || filled($connected->access_token) || filled($connected->refresh_token))
                    && (strtolower(trim($connected->subdomain)) !== strtolower(trim($account->subdomain))
                        || strtolower($connected->zone ?: 'ru') !== strtolower($account->zone ?: 'ru'))
                );
            if ($other) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'subdomain' => 'Все виджеты аккаунта платформы должны подключаться к одному amoCRM: '.$other->subdomain.'.',
                ]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staffs(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class);
    }

    public static function normalizeWidget(?string $widget): string
    {
        $normalized = mb_strtolower(trim((string)$widget));

        return $normalized !== '' ? $normalized : self::DEFAULT_WIDGET;
    }
}
