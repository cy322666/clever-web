<?php

namespace App\Models\Integrations\Calculator;

use App\Models\Core\Account;
use App\Models\User;
use App\Models\Workflows\Workflow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 0;
    public const STATUS_SUCCESS = 1;
    public const STATUS_ERROR = 2;

    protected $table = 'calculator_transactions';

    protected $fillable = [
        'user_id',
        'account_id',
        'calculator_setting_id',
        'workflow_id',
        'entity_type',
        'entity_id',
        'field_id',
        'field_name',
        'expression',
        'result_value',
        'status',
        'error_message',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'calculator_setting_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
