<?php

namespace App\Models\Integrations\Bizon;

use App\Models\App;
use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property mixed $account
 */
class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'pipeline_id',
        'user_id',
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
        'password',
        'status_id_form',
        'pipeline_id_form',
        'responsible_user_id_form',
        'tag_form',
        'status_id_form',
        'pipeline_id_form',
        'responsible_user_id_form',
        'tag_form',
    ];

    protected $table = 'bizon_settings';

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function getWebinarLink(): string
    {
        return route('bizon.hook', ['user' => $this->user->uuid]);
    }

    public function getFormLink(): string
    {
        return route('bizon.form', ['user' => $this->user->uuid]);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'id','setting_id')
            ->where('user_id', $this->user_id);
    }
}
