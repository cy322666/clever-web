<?php

namespace App\Console\Commands\Edtech;

use App\Models\Core\Account;
use App\Models\User;
use App\Services\amoCRM\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

class CreateFields extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-fields {user_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = User::query()->find($this->argument('user_id'));

        $amoApi = (new Client($user->account))
            ->setDelay(0.5)
            ->initLogs(Env::get('APP_DEBUG'));

        $amoApi->service->ajax()->post('/api/v4/leads/custom_fields', [
            'type' => 'select',
            'name' => 'Продукт',
            'sort' => 100,
            'enums' => [
                ['value' => 'Продукт 1'],
                ['value' => 'Продукт 2'],
                ['value' => 'Продукт 3'],
                ['value' => 'Продукт 4'],
            ],
            //required_statuses
        ]);

        $amoApi->service->ajax()->post('/api/v4/leads/custom_fields', [
            'type' => 'radiobutton',
            'name' => 'Тип пакета',
            'sort' => 101,
            'enums' => [
                ['value' => 'Пробный'],
                ['value' => 'Полный'],
            ],
            //required_statuses
        ]);

        $amoApi->service->ajax()->post('/api/v4/leads/custom_fields', [
            'type' => 'select',
            'name' => 'Уровень знаний',
            'sort' => 102,
            'enums' => [
                ['value' => 'Начальный'],
                ['value' => 'Базовый'],
                ['value' => 'Продвинутый'],
            ],
            //required_statuses
        ]);

        $amoApi->service->ajax()->post('/api/v4/leads/custom_fields', [
            'type' => 'radiobutton',
            'name' => 'Тип оплаты',
            'sort' => 103,
            'enums' => [
                ['value' => 'Наличные'],
                ['value' => 'СБП'],
                ['value' => 'GetCourse'],
            ],
            //required_statuses
        ]);

        $amoApi->service->ajax()->post('/api/v4/leads/custom_fields', [
            'type' => 'select',
            'name' => 'Форма оплаты',
            'sort' => 104,
            'enums' => [
                ['value' => '100%'],
                ['value' => '50/50'],
                ['value' => 'Рассрочка'],
            ],
            //required_statuses
        ]);

        $amoApi->service->ajax()->post('/api/v4/leads/custom_fields', [
            'type' => 'date',
            'name' => 'Дата следующей покупки',
            'sort' => 105,
            //required_statuses
        ]);

        $amoApi->service->ajax()->post('/api/v4/leads/custom_fields', [
            'type' => 'select',
            'name' => 'Источник (канал)',
            'sort' => 106,
            'enums' => [
                ['value' => 'Телеграм бот'],
                ['value' => 'Звонок'],
                ['value' => 'Вацап'],
            ],
            //required_statuses
        ]);

        $amoApi->service->ajax()->post('/api/v4/leads/custom_fields', [
            'type' => 'select',
            'name' => 'Источник (маркетинг)',
            'sort' => 107,
            'enums' => [
                ['value' => 'Органика'],
                ['value' => 'Яндекс директ'],
                ['value' => 'СЕО'],
            ],
            //required_statuses
        ]);

        $amoApi->service->ajax()->post('/api/v4/leads/custom_fields', [
            'type' => 'select',
            'name' => 'Причина отказа',
            'sort' => 108,
            'enums' => [
                ['value' => 'Дорого'],
                ['value' => 'Потерялся'],
                ['value' => 'Купил у конкурента'],
            ],
            //required_statuses
        ]);

        $amoApi->service->ajax()->post('/api/v4/contacts/custom_fields', [
            'type' => 'text',
            'name' => 'Страна',
        ]);
    }
}
