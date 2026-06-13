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
        'name' => [
            'label' => 'Название',
            'placeholder' => 'Например: stripe_api_key',
            'helper' => 'Уникальный идентификатор секрета. Используйте snake_case.',
        ],
        'value' => [
            'label' => 'Значение секрета',
            'placeholder' => 'Введите значение секрета...',
            'helper_create' => 'Значение будет зашифровано перед сохранением.',
            'helper_edit' => 'Оставьте пустым, чтобы сохранить текущее значение.',
            'display' => '********',
        ],
        'type' => ['label' => 'Тип'],
        'description' => ['label' => 'Описание'],
        'last_used_at' => [
            'label' => 'Последнее использование',
            'never' => 'Никогда',
        ],
        'created_at' => ['label' => 'Создан'],
        'updated_at' => ['label' => 'Изменен'],
    ],
    'filters' => [
        'type' => ['label' => 'Тип'],
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
    'actions' => [
        'edit' => [
            'description' => 'Обновите данные секрета. Оставьте значение пустым, чтобы сохранить текущий секрет.',
        ],
        'create' => [
            'description' => 'Безопасно сохраните чувствительные значения. Они будут зашифрованы.',
        ],
    ],
    'empty_state' => [
        'heading' => 'Секретов пока нет',
        'description' => 'Создайте секрет для безопасного хранения API-ключей, токенов и других чувствительных данных.',
    ],
]);
