<?php

namespace App\Models\Cases;

use Illuminate\Database\Eloquent\Model;

class CompanyCase extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'company_name',
        'industry',
        'excerpt',
        'description',
        'content_blocks',
        'logo_url',
        'cover_url',
        'tags',
        'sort',
        'is_featured',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'content_blocks' => 'array',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];
}
