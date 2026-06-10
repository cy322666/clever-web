<?php

$fallback = require base_path('vendor/leek/filament-workflows/resources/lang/en/secrets.php');

return array_replace_recursive($fallback, [
    'navigation' => [
        'label' => 'Секреты процессов',
    ],
    'model' => [
        'singular' => 'секрет',
        'plural' => 'Секреты',
    ],
    'fields' => [
        'name' => ['label' => 'Название'],
        'value' => ['label' => 'Значение'],
        'type' => ['label' => 'Тип'],
        'description' => ['label' => 'Описание'],
        'last_used_at' => ['label' => 'Последнее использование'],
        'created_at' => ['label' => 'Создан'],
        'updated_at' => ['label' => 'Изменен'],
    ],
    'sections' => [
        'secret_details' => [
            'title' => 'Данные секрета',
            'description' => 'Безопасно храните API-ключи, токены и URL для процессов.',
        ],
    ],
    'types' => [
        'api_key' => 'API-ключ',
        'bearer_token' => 'Bearer token',
        'url' => 'URL',
        'custom' => 'Свое значение',
    ],
]);
