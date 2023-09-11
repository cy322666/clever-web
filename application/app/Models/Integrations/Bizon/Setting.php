<?php

namespace App\Models\Integrations\Bizon;

use App\Filament\Resources\Integrations\BizonResource;
use App\Helpers\Traits\SettingRelation;
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
    use HasFactory, SettingRelation;

    public static array $cost = [
        '1_month'  => '500 р',
        '6_month'  => '3000 р',
        '12_month' => '5000 р',
    ];

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

    public static string $resource = BizonResource::class;

    public function getWebinarLink(): string
    {
        return route('bizon.hook', ['user' => $this->user->uuid]);
    }

    public function getFormLink(): string
    {
        return route('bizon.form', ['user' => $this->user->uuid]);
    }
}
