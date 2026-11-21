<?php

namespace App\Models\Integrations\YClients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordEvent extends Model
{
    protected $table = 'yclients_record_events';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'source_snapshot' => 'array',
        'record_snapshot' => 'array',
        'client_snapshot' => 'array',
        'create_note' => 'boolean',
        'available_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }
}
