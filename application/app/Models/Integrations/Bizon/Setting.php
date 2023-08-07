<?php

namespace App\Models\Integrations\Bizon;

use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property mixed $account
 */
class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'pipeline_id',
        'account_id',
        'status_id_cold',
        'status_id_soft',
        'status_id_hot',
        'response_user_id',
        'tag',
        'time_cold',
        'time_soft',
        'time_hot',
        'tag_cold',
        'tag_soft',
        'tag_hot',
        'token',
        'login',
        'password'
    ];

    protected $table = 'bizon_settings';

    public function user(): HasOne
    {
        return $this->hasOne(Account::class);
    }
}
