<?php

$fallback = require base_path('vendor/leek/filament-workflows/resources/lang/en/variables.php');

return array_replace_recursive($fallback, [
    'categories' => [
        'system' => 'Система',
        'trigger_model' => 'Модель триггера',
        'trigger_relations' => 'Связи триггера',
        'trigger_dates' => 'Даты триггера',
        'trigger_status' => 'Статус триггера',
        'previous_steps' => 'Предыдущие шаги',
    ],
    'built_in' => [
        'now' => [
            'label' => 'Текущая дата и время',
            'description' => 'Текущая дата и время выполнения процесса',
        ],
    ],
    'descriptions' => [
        'model_field' => ':label из :model',
        'model_attribute' => 'Атрибут :attribute из :model',
        'model_date_field' => 'Дата из :model',
        'model_status_field' => 'Статус из :model',
        'model_status_field_with_values' => 'Статус из :model: :values',
        'output_from_step' => 'Результат шага ":name"',
        'nested_fields' => 'Можно обращаться к вложенным полям через точку',
    ],
    'outputs' => [
        'condition_passed' => [
            'label' => 'Условие выполнено',
            'description' => 'Логический результат',
        ],
        'branch_taken' => [
            'label' => 'Выбранная ветка',
            'description' => '"true" или "false"',
        ],
        'http_status' => [
            'label' => 'HTTP-статус',
            'description' => 'Код статуса ответа',
        ],
        'response_body' => [
            'label' => 'Тело ответа',
            'description' => 'Содержимое ответа',
        ],
        'response_headers' => [
            'label' => 'Заголовки ответа',
            'description' => 'Заголовки ответа',
        ],
        'transformed_data' => [
            'label' => 'Преобразованные данные',
            'description' => 'Результат преобразования',
        ],
        'variable_value' => [
            'label' => 'Значение переменной',
            'description' => 'Установленное значение',
        ],
    ],
    'placeholders' => [
        'unknown' => 'Неизвестно',
    ],
]);
