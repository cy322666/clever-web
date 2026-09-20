<?php

namespace App\Models\Workflows;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowCredential extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected $casts = ['secret' => 'encrypted'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
