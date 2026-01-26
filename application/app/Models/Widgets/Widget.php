<?php

namespace App\Models\Widgets;

use Illuminate\Database\Eloquent\Model;

class Widget extends Model
{
    protected $fillable = [
        'slug',
        'title',
        'excerpt',
        'description',
        'website_url',
        'demo_vk_url',
        'demo_youtube_url',
        'install_url',
        'tags',
        'pricing_type',
        'price_from_rub',
        'trial_days',
        'installs_count',
        'is_featured',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function categories()
    {
        return $this->belongsToMany(WidgetCategory::class, 'widget_category');
    }
}
