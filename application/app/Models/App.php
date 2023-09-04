<?php

namespace App\Models;

use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class App extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_name',
        'setting_id',
        'user_id',
        'name',
        'active',
    ];

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function setting(): HasOne
    {
//        $modelName = $this->resource_name::$model;
//
//        return $this->hasOne($modelName::class, 'id', 'setting_id');
    }
}
