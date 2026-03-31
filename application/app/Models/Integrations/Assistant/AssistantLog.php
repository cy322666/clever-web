<?php

namespace App\Models\Integrations\Assistant;

use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantLog extends Model
{
    use HasFactory;

    protected $table = 'assistant_logs';

    protected $fillable = [
        'assistant_setting_id',
        'user_id',
        'account_id',
        'source',
        'status',
        'endpoint',
        'tool',
        'model',
        'prompt_version',
        'latency_ms',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'request_payload',
        'response_payload',
        'error',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'assistant_setting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
