<?php

return [
    'install_status_store' => 'database',

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'message_thread_id' => env('TELEGRAM_MESSAGE_THREAD_ID'),
    ],

    'labels' => [
        'amocrm' => 'amoCRM',
        'workflows' => 'Потоки',
        'import-excel' => 'Импорт Excel',
        'finder' => 'Контроль ответов',
        'sqns' => 'SQNS',
        'yclients' => 'YCLIENTS',
        'vetmanager' => 'Ветменеджер',
        'distribution' => 'Распределение',
        'industry-clinics-salons' => 'Клиники и салоны',
    ],
];
