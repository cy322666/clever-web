<?php

$fallback = require base_path('vendor/leek/filament-workflows/resources/lang/en/triggers.php');

return array_replace_recursive($fallback, [
    'common' => [
        'fields' => [
            'record_type' => [
                'label' => 'Тип записи',
                'label_optional' => 'Тип записи (необязательно)',
                'helper' => 'Какой тип записи должен запускать процесс?',
                'manual_helper' => 'Если указано, процесс можно запускать для конкретных записей',
            ],
            'state_field' => ['label' => 'Поле состояния'],
        ],
        'validation' => [
            'record_type_required' => 'Выберите тип записи',
            'model_missing' => 'Выбранный класс модели не найден',
        ],
        'errors' => [
            'invalid_class' => 'Класс триггера :class должен реализовать :contract',
            'unknown_type' => 'Неизвестный тип триггера: :type',
        ],
        'placeholders' => [
            'tbd' => 'Будет позже',
        ],
    ],
    'model_created' => [
        'name' => 'При создании',
        'description' => 'Запускается при создании новой записи',
        'configured' => [
            'summary' => 'Когда создана новая запись <strong>:model</strong>',
        ],
    ],
    'model_updated' => [
        'name' => 'При обновлении',
        'description' => 'Запускается при обновлении записи',
        'fields' => [
            'watch_fields' => [
                'label' => 'Отслеживаемые поля (необязательно)',
                'helper' => 'Запускать только при изменении выбранных полей. Оставьте пустым, чтобы запускать при любом обновлении.',
            ],
        ],
        'configured' => [
            'any_field' => 'Когда <strong>:model</strong> обновлена',
            'one_field' => 'Когда поле <strong>:field</strong> у <strong>:model</strong> обновлено',
            'many_fields' => 'Когда <strong>:model</strong> обновлена (:count полей отслеживается)',
        ],
    ],
    'model_deleted' => [
        'name' => 'При удалении',
        'description' => 'Запускается при удалении записи',
        'configured' => [
            'summary' => 'Когда <strong>:model</strong> удалена',
        ],
    ],
    'manual' => [
        'name' => 'Ручной запуск',
        'description' => 'Запуск вручную из интерфейса или API',
        'info' => 'Ручные триггеры можно запускать со страницы процесса или через API.',
        'configured' => [
            'summary' => 'Ручной запуск для записей <strong>:model</strong>',
        ],
    ],
    'schedule' => [
        'name' => 'По расписанию',
        'description' => 'Запускается по повторяющемуся расписанию',
        'fields' => [
            'frequency' => [
                'label' => 'Периодичность',
                'helper' => 'Как часто запускать процесс?',
            ],
            'time' => [
                'label' => 'Время',
                'placeholder' => '09:00',
                'helper' => 'Время запуска в формате HH:MM',
            ],
            'day_of_week' => ['label' => 'День недели'],
            'day_of_month' => [
                'label' => 'День месяца',
                'placeholder' => '1',
                'helper' => 'День месяца от 1 до 28',
            ],
            'cron_expression' => [
                'label' => 'Cron-выражение',
                'placeholder' => '0 9 * * *',
                'helper' => 'Свое cron-выражение, например "0 9 * * *" для ежедневного запуска в 09:00',
            ],
        ],
        'frequencies' => [
            'hourly' => 'Каждый час',
            'daily' => 'Ежедневно',
            'weekly' => 'Еженедельно',
            'monthly' => 'Ежемесячно',
            'custom' => 'Свое расписание (cron)',
            'hourly_description' => 'Каждый час',
            'daily_description' => 'Ежедневно',
            'weekly_description' => 'Еженедельно',
            'monthly_description' => 'Ежемесячно',
            'custom_description' => 'Свое расписание',
        ],
        'days' => [
            '0' => 'Воскресенье',
            '1' => 'Понедельник',
            '2' => 'Вторник',
            '3' => 'Среда',
            '4' => 'Четверг',
            '5' => 'Пятница',
            '6' => 'Суббота',
        ],
        'configured' => [
            'custom' => 'Запускается по <strong>своему расписанию</strong>: :cron',
            'weekly' => 'Запускается <strong>:frequency</strong>, день: <strong>:day</strong>:time',
            'monthly' => 'Запускается <strong>:frequency</strong>, день месяца: <strong>:day</strong>:time',
            'at_time' => 'Запускается <strong>:frequency</strong> в <strong>:time</strong>',
            'default' => 'Запускается <strong>:frequency</strong>',
            'time_suffix' => ' в <strong>:time</strong>',
        ],
        'validation' => [
            'frequency_required' => 'Выберите периодичность',
            'cron_required' => 'Введите cron-выражение',
            'cron_invalid' => 'Некорректный формат cron-выражения',
        ],
    ],
    'date_condition' => [
        'name' => 'Условие по дате',
        'description' => 'Запускается относительно даты в поле',
        'fields' => [
            'date_field' => [
                'label' => 'Поле даты',
                'helper' => 'Поле даты для проверки',
            ],
            'operator' => ['label' => 'Условие'],
            'offset_days' => [
                'label' => 'Дней',
                'helper' => 'Количество дней до/после даты',
            ],
        ],
        'operators' => [
            'days_before' => 'Дней до',
            'days_after' => 'Дней после',
            'on' => 'В точную дату',
            'days_before_description' => 'дней до',
            'days_after_description' => 'дней после',
            'on_description' => 'в дату',
        ],
        'fallback_fields' => [
            'created_at' => 'Создано',
            'updated_at' => 'Обновлено',
        ],
        'validation' => [
            'date_field_required' => 'Укажите поле даты',
        ],
    ],
    'status_changed' => [
        'name' => 'При изменении статуса',
        'description' => 'Запускается, когда статус записи меняется на указанное значение',
        'fields' => [
            'status_field' => [
                'label' => 'Поле статуса',
                'helper' => 'Поле, в котором хранится статус',
                'default' => 'Статус',
            ],
            'from_status' => [
                'label' => 'Из статуса (необязательно)',
                'helper' => 'Запускать только при переходе из этого статуса',
            ],
            'to_status' => [
                'label' => 'В статус',
                'helper' => 'Запускать при переходе в этот статус',
            ],
        ],
        'validation' => [
            'target_status_required' => 'Укажите целевой статус',
        ],
    ],
    'event' => [
        'name' => 'При событии',
        'description' => 'Запускается при отправке Laravel-события',
        'fields' => [
            'event_class' => [
                'label' => 'Событие',
                'helper' => 'Какое событие должно запускать процесс?',
            ],
            'conditions' => [
                'label' => 'Условия',
                'helper' => 'Можно отфильтровать события по их свойствам',
                'add' => 'Добавить условие',
            ],
            'property' => [
                'label' => 'Свойство',
                'placeholder' => 'Выберите свойство',
            ],
            'value' => [
                'label' => 'Значение',
                'placeholder' => 'Значение для сравнения',
            ],
        ],
    ],
    'webhook' => [
        'name' => 'Webhook',
        'description' => 'Запускается при получении webhook',
    ],
    'state_transition' => [
        'name' => 'Переход состояния',
        'description' => 'Запускается при переходе состояния модели',
    ],
]);
