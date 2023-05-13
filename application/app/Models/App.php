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
        'account_id',
        'user_id',
        'name',
        'active',
    ];

    public function account(): HasOne
    {
        return $this->hasOne(Account::class, 'id', 'account_id');
    }
}
