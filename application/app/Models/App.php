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

    const STATE_CREATED_WORD  = '';
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

    public static function getTooltipText(string $appName): string
    {
        return match ($appName) {
            'alfacrm' => 'Синхронизируйте клиентов и их посещения между amoCRM и АльфаСРМ',
            'distribution' => 'Гибко настройте распределение сделок между менеджерами',
            'getcourse' => 'Интегрируйте заявки и заказы из GetCourse с amoCRM',
            'bizon' => 'Настройте отправку регистрвцикй и посещений из Бизон 365',
            'tilda' => 'Отправляйте заявки с вашего сайта на Tilda в amoCRM без дублей',
            'active-lead' => 'Настройте проверку активности клиента при создании сделки',
            'data-info' => 'Поможет узнать часовой пояс клиента по номеру телефона',
            'yclients' => 'Синхронизируйте клиентов и их посещения между amoCRM и YClients',
            'docs' => 'Создавайте документы на основе ваших шаблонов',
            'tables' => ' ',
            'analytic' => ' ',
        };
    }

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

    public static function isActiveWidget(Model $setting) : bool
    {
        return $setting->active;
    }
}
