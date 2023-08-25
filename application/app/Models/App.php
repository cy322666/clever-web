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
        return $this->hasOne(Account::class, 'id', 'setting_id');//TODO
    }
}
