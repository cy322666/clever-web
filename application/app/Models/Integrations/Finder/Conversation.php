<?php

namespace App\Models\Integrations\Finder;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    protected $table = 'finder_conversations';

    protected $guarded = [];

    protected $casts = ['pending_since' => 'immutable_datetime', 'last_message_at' => 'immutable_datetime', 'last_outgoing_at' => 'immutable_datetime', 'next_check_at' => 'immutable_datetime'];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }
}
