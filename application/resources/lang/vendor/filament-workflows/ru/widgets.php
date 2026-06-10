<?php

$fallback = require base_path('vendor/leek/filament-workflows/resources/lang/en/widgets.php');

return array_replace_recursive($fallback, [
    'metrics' => [
        'heading_scoped' => 'Производительность процессов',
        'heading' => 'Метрики процессов',
        'description' => 'Статистика выполнения за последние 24 часа',
        'runs' => [
            'label' => 'Запуски за 24 часа',
            'description' => ':completed завершено, :failed с ошибкой',
        ],
        'success_rate' => [
            'label' => 'Успешность за 24 часа',
        ],
        'duration' => [
            'label' => 'Среднее время выполнения',
            'p95' => 'P95: :duration',
            'not_available' => 'н/д',
        ],
        'failed' => [
            'label' => 'Ошибки за 24 часа',
        ],
        'trends' => [
            'no_prior_data' => 'Нет предыдущих данных',
            'from_yesterday' => ':change со вчера',
            'no_failures' => 'Ошибок нет',
            'new_failures' => 'Новые ошибки',
            'same_as_yesterday' => 'Без изменений',
            'more_than_yesterday' => 'на :count больше, чем вчера',
            'fewer_than_yesterday' => 'на :count меньше, чем вчера',
        ],
    ],
]);
