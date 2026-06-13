<?php

namespace App\Models\Billing;

use App\Models\App;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WidgetSubscription extends Model
{
    use SoftDeletes;

    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_GRACE = 'grace';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [
        self::STATUS_TRIAL,
        self::STATUS_ACTIVE,
        self::STATUS_GRACE,
    ];

    protected $fillable = [
        'user_id',
        'app_id',
        'subscription_plan_id',
        'widget',
        'status',
        'starts_at',
        'ends_at',
        'grace_until',
        'blocked_at',
        'last_notified_at',
        'notification_log',
        'notes',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'grace_until' => 'date',
        'blocked_at' => 'datetime',
        'last_notified_at' => 'datetime',
        'notification_log' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function scopeForWidget(Builder $query, string $widget): Builder
    {
        return $query->where('widget', $widget);
    }

    public function scopeCurrentlyUsable(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query
            ->whereNull('blocked_at')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where(function (Builder $query) use ($today): void {
                $query
                    ->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $today);
            })
            ->where(function (Builder $query) use ($today): void {
                $query
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $today)
                    ->orWhere('grace_until', '>=', $today);
            });
    }

    public function isCurrentlyUsable(): bool
    {
        if ($this->blocked_at !== null || !in_array($this->status, self::ACTIVE_STATUSES, true)) {
            return false;
        }

        $today = now()->startOfDay();

        if ($this->starts_at !== null && $this->starts_at->startOfDay()->gt($today)) {
            return false;
        }

        if ($this->ends_at === null || $this->ends_at->startOfDay()->gte($today)) {
            return true;
        }

        return $this->grace_until !== null && $this->grace_until->startOfDay()->gte($today);
    }

    public function statusLabel(): string
    {
        return self::statusOptions()[$this->status] ?? $this->status;
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_TRIAL => 'Тестовый период',
            self::STATUS_ACTIVE => 'Активна',
            self::STATUS_GRACE => 'Льготный период',
            self::STATUS_EXPIRED => 'Истекла',
            self::STATUS_BLOCKED => 'Заблокирована',
            self::STATUS_CANCELLED => 'Отменена',
        ];
    }
}
