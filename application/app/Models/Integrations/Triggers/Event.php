<?php

namespace App\Models\Integrations\Triggers;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $table = 'trigger_events';

    protected $fillable = [
        "event_id",
        "type",
        "entity_id",
        "entity_type",
        "event_created_by",
        "event_created_at",
        "value_after",
        "value_before",
        "event_account_id",
        "account_id",
        'user_id',
    ];
}
