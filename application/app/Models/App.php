<?php

namespace App\Models;

use App\Models\Core\Account;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class App extends Model
{
    use HasFactory;

    const STATE_CREATED  = 0;
    const STATE_INACTIVE = 1;
    const STATE_ACTIVE   = 2;
    const STATE_EXPIRES  = 3;

    const STATE_CREATED_WORD  = 'Не настроена';
    const STATE_INACTIVE_WORD = 'Не активна';
    const STATE_ACTIVE_WORD   = 'Активна';
    const STATE_EXPIRES_WORD  = 'Закончилась';

    protected $fillable = [
        'resource_name',
        'setting_id',
        'user_id',
        'name',
        'expires_tariff_at',
        'status',
    ];

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    /*
     * @active - статус в настройках интеграции
     * @model  - модель настроек интеграции
     *
     * учитывает тариф для присвоения статуса приложению
     *
     * @param Model $setting
     * @return Model
     */
    public function setStatusWithActive(Model $setting) : Model
    {
        if (Carbon::parse($this->expires_tariff_at) < Carbon::now())  {

            $this->status = App::STATE_EXPIRES;
            $this->save();

            $setting->active = false;
            $setting->save();
        } else {

            $this->status = $setting->active ? App::STATE_ACTIVE : App::STATE_INACTIVE;
            $this->save();
        }

        return  $setting;
    }

    /**
     * - при смене статуса по кнопке отправляет уведомление
     *
     * @return void
     */
    public function sendNotificationStatus() : void
    {
        if ($this->status == App::STATE_INACTIVE) {

            Notification::make()
                ->title('Интеграция выключена')
                ->danger()
                ->send();

        } elseif ($this->status == App::STATE_ACTIVE) {

            Notification::make()
                ->title('Интеграция включена')
                ->success()
                ->send();

        } elseif ($this->status == App::STATE_EXPIRES) {

            Notification::make()
                ->title('Интеграция не оплачена')
                ->warning()
                ->send();
        }
    }
}
