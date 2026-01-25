<?php

namespace App\Models\Integrations\ContactMerge;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Record extends Model
{
    use HasFactory;

    protected $table = 'contact_merge_records';

    protected $fillable = [
        'setting_id',
        'user_id',
        'master_contact_id',
        'duplicate_contact_id',
        'match_fields',
        'changes',
        'status',
        'message',
    ];

    protected $casts = [
        'match_fields' => 'array',
        'changes' => 'array',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }
}
