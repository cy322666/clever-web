<?php

// app/Models/WidgetCategory.php
namespace App\Models\Widgets;

use App\Models\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class WidgetCategory extends Model
{
    protected $fillable = ['slug', 'name', 'sort'];

    public function widgets()
    {
        return $this->belongsToMany(Widget::class, 'widget_category');
    }
}

