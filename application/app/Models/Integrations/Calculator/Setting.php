<?php

namespace App\Models\Integrations\Calculator;

use App\Filament\Resources\Integrations\CalculatorResource;
use App\Helpers\Traits\SettingRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'calculator_settings';

    public static string $resource = CalculatorResource::class;

    public static string $description = 'Автоматически рассчитывайте значения по формулам и записывайте результат в поля amoCRM.';

    public static array $instruction = [
        'Синхронизируйте поля amoCRM, чтобы выбрать поле результата',
        'Добавьте формулу и задайте выражение с переменными процессов или webhook',
        'Выберите сущность и поле, куда нужно записать результат',
        'Используйте действие "Калькулятор полей" в конструкторе процессов',
        'Проверьте расчет на тестовой сделке перед включением в рабочую воронку',
    ];

    public static array $cost = [
        '1_month' => '1 990 ₽',
        '6_month' => '9 900 ₽',
        '12_month' => '17 900 ₽',
    ];

    protected $fillable = [
        'formulas',
        'active',
        'user_id',
        'account_id',
    ];

    protected $casts = [
        'formulas' => 'array',
        'active' => 'boolean',
    ];
}
