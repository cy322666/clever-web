<?php

$fallback = require base_path('vendor/leek/filament-workflows/resources/lang/en/templates.php');

return array_replace_recursive($fallback, [
    'categories' => [
        'communication' => 'Коммуникации',
        'records' => 'Записи',
        'integrations' => 'Интеграции',
        'scheduling' => 'Расписание',
        'custom' => 'Свое',
    ],
    'variables' => [
        'label' => 'Переменные',
        'name' => 'Название',
        'description' => 'Описание',
        'type' => 'Тип',
        'default' => 'По умолчанию',
        'required' => 'Обязательно',
    ],
    'actions' => [
        'use_template' => 'Использовать шаблон',
        'preview' => 'Предпросмотр',
    ],
]);
