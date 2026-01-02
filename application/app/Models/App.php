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
    const STATE_INACTIVE = 2;
    const STATE_ACTIVE   = 1;
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
        'installed_at',
    ];

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            App::STATE_CREATED  => App::STATE_CREATED_WORD,
            App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
            App::STATE_ACTIVE   => App::STATE_ACTIVE_WORD,
            App::STATE_EXPIRES  => App::STATE_EXPIRES_WORD,
        };
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function getSettingModel()
    {
        return $this->resource_name::getModel()::query()->find($this->setting_id);
    }

    /**
     * - при смене статуса по кнопке отправляет уведомление TODO сделать и себе в тг
     *
     * TODO не работает
     * @return void
     */
    public function sendNotificationStatus() : void
    {
        if (!$this->status == App::STATE_INACTIVE) {

            Notification::make()
                ->title('Интеграция выключена')
                ->danger()
                ->send();

        } elseif (!$this->status == App::STATE_ACTIVE) {

            Notification::make()
                ->title('Интеграция включена')
                ->success()
                ->send();

        } elseif (!$this->status == App::STATE_EXPIRES) {

            Notification::make()
                ->title('Интеграция не оплачена')
                ->warning()
                ->send();
        }
    }

    public static function isActiveWidget(Model $setting) : bool
    {
        return $setting->active;
    }
}
