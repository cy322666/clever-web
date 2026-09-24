<?php

namespace App\Models\Integrations\Finder;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Action extends Model
{
    protected $table = 'finder_actions';

    protected $guarded = [];

    protected $casts = ['payload' => 'array'];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
