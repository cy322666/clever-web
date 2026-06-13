<?php

namespace App\Models\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionInvoiceRequest extends Model
{
    use SoftDeletes;

    public const STATUS_NEW = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_INVOICE_SENT = 'invoice_sent';
    public const STATUS_PAID_MANUAL = 'paid_manual';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'widget',
        'status',
        'contact_name',
        'contact_email',
        'contact_phone',
        'comment',
        'manager_note',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_NEW => 'Новая',
            self::STATUS_IN_PROGRESS => 'В работе',
            self::STATUS_INVOICE_SENT => 'Счет отправлен',
            self::STATUS_PAID_MANUAL => 'Оплачено вручную',
            self::STATUS_CANCELLED => 'Отменена',
        ];
    }
}
