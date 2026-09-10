<?php

namespace App\Models\Integrations\Vetmanager;

use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $table = 'vetmanager_visits';

    protected $fillable = [
        'external_id',
        'event_name',
        'status',
        'vetmanager_status',
        'client_id',
        'patient_id',
        'clinic_id',
        'doctor_id',
        'client_name',
        'patient_name',
        'doctor_name',
        'admission_date',
        'amount',
        'contact_id',
        'lead_id',
        'event_payload',
        'admission_payload',
        'error_message',
        'attempts',
        'processed_at',
        'user_id',
        'account_id',
        'setting_id',
    ];

    protected $casts = [
        'event_payload' => 'array',
        'admission_payload' => 'array',
        'admission_date' => 'datetime',
        'processed_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
