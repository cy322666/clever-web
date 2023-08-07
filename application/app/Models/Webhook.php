<?php

namespace App\Models;

use App\Models\AlfaCRM\Setting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Psy\Util\Str;

class Webhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_name',
        'app_id',
        'active',
        'path',
        'type',
        'platform',
        'uuid',
        'user_id',
        'params',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bizonSetting()
    {
        return $this->belongsTo(\App\Models\Bizon\Setting::class, 'setting_id', 'id');
    }

    public function alfaSetting()
    {
        return $this->belongsTo(Setting::class, 'setting_id', 'id');
    }

    public function getcourseSetting()
    {
        return $this->belongsTo(\App\Models\GetCourse\Setting::class, 'setting_id', 'id');
    }
}
