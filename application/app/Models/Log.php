<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Log extends Model
{
    use HasFactory;

    protected $table = 'amocrm_logs';

    protected $fillable = [
        'data',
        'url',
        'code',
        'start',
        'end',
        'method',
        'error',
        'details',
        'user_id',
        'args',
        'body',
        'retries',
        'memory_usage',
        'execution_time',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
