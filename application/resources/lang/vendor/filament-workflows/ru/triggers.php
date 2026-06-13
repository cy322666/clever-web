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
        'configured' => [
            'on' => 'Когда дата <strong>совпадает</strong> с полем <strong>:field</strong> у <strong>:model</strong>',
            'relative' => 'Когда дата <strong>:days</strong> <strong>:operator</strong> поля <strong>:field</strong> у <strong>:model</strong>',
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
        'configured' => [
            'any' => 'Когда у <strong>:model</strong> меняется статус',
            'from_to' => 'Когда у <strong>:model</strong> статус меняется с <strong>:from</strong> на <strong>:to</strong>',
            'to' => 'Когда у <strong>:model</strong> статус меняется на <strong>:to</strong>',
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
        'operators' => [
            'equals' => 'Равно',
            'not_equals' => 'Не равно',
            'contains' => 'Содержит',
            'not_contains' => 'Не содержит',
            'gt' => 'Больше',
            'gte' => 'Больше или равно',
            'lt' => 'Меньше',
            'lte' => 'Меньше или равно',
            'is_empty' => 'Пусто',
            'is_not_empty' => 'Не пусто',
            'contains_short' => 'содержит',
            'not_contains_short' => 'не содержит',
            'is_empty_short' => 'пусто',
            'is_not_empty_short' => 'не пусто',
        ],
        'configured' => [
            'summary' => 'Когда отправлено событие <strong>:event</strong>',
            'condition_suffix' => ' (с условиями: :count)',
            'condition_singular' => 'условие',
            'condition_plural' => 'условий',
        ],
        'validation' => [
            'event_required' => 'Выберите событие',
            'event_missing' => 'Выбранный класс события не существует',
            'property_required' => 'Условие #:number: укажите свойство',
            'operator_required' => 'Условие #:number: выберите оператор',
            'value_required' => 'Условие #:number: укажите значение',
        ],
        'default_vendor_events' => [
            'login' => 'Пользователь вошел',
            'logout' => 'Пользователь вышел',
            'registered' => 'Пользователь зарегистрирован',
            'verified' => 'Email подтвержден',
            'password_reset' => 'Пароль сброшен',
            'failed' => 'Ошибка входа',
            'lockout' => 'Аккаунт заблокирован',
            'message_sent' => 'Email отправлен',
            'message_sending' => 'Email отправляется',
            'notification_sent' => 'Уведомление отправлено',
            'notification_sending' => 'Уведомление отправляется',
            'job_processed' => 'Задача обработана',
            'job_failed' => 'Задача завершилась с ошибкой',
        ],
    ],
    'webhook' => [
        'name' => 'Webhook',
        'description' => 'Запускается при получении webhook',
        'fields' => [
            'config_name' => [
                'label' => 'Настройка webhook',
                'helper' => 'Какая настройка webhook-клиента должна запускать процесс?',
            ],
            'conditions' => [
                'label' => 'Условия',
                'helper' => 'Можно отфильтровать webhook по payload, заголовкам, URL или названию настройки',
                'add' => 'Добавить условие',
            ],
            'path' => [
                'label' => 'Путь',
                'placeholder' => 'payload.event_type',
                'helper' => 'Примеры: payload.type, headers.x-github-event.0, name, url',
            ],
            'value' => [
                'label' => 'Значение',
                'placeholder' => 'Значение для сравнения',
            ],
        ],
        'operators' => [
            'equals' => 'Равно',
            'not_equals' => 'Не равно',
            'contains' => 'Содержит',
            'not_contains' => 'Не содержит',
            'gt' => 'Больше',
            'gte' => 'Больше или равно',
            'lt' => 'Меньше',
            'lte' => 'Меньше или равно',
            'is_empty' => 'Пусто',
            'is_not_empty' => 'Не пусто',
            'contains_short' => 'содержит',
            'not_contains_short' => 'не содержит',
            'is_empty_short' => 'пусто',
            'is_not_empty_short' => 'не пусто',
        ],
        'configured' => [
            'summary' => 'Когда настройка webhook <strong>:name</strong> получает запрос',
            'condition_suffix' => ' (с условиями: :count)',
            'condition_singular' => 'условие',
            'condition_plural' => 'условий',
        ],
        'validation' => [
            'config_name_required' => 'Выберите настройку webhook',
            'path_required' => 'Условие #:number: укажите путь',
            'operator_required' => 'Условие #:number: выберите оператор',
            'value_required' => 'Условие #:number: укажите значение',
        ],
    ],
    'state_transition' => [
        'name' => 'Переход состояния',
        'description' => 'Запускается при переходе состояния модели',
        'fields' => [
            'model' => [
                'helper' => 'У какой модели отслеживать смену состояния?',
            ],
            'state_field' => [
                'helper' => 'Какое поле состояния отслеживать?',
            ],
            'from_state' => [
                'label' => 'Из состояния (необязательно)',
                'helper' => 'Запускать только при переходе из этого состояния',
            ],
            'to_state' => [
                'label' => 'В состояние (необязательно)',
                'helper' => 'Запускать только при переходе в это состояние',
            ],
        ],
        'configured' => [
            'base' => 'Когда <strong>:model</strong>',
            'field' => ' <strong>:field</strong>',
            'from_to' => ' переходит из <strong>:from</strong> в <strong>:to</strong>',
            'to' => ' переходит в <strong>:to</strong>',
            'from' => ' переходит из <strong>:from</strong>',
            'any' => ' меняет состояние',
        ],
        'validation' => [
            'state_field_required' => 'Выберите поле состояния',
        ],
    ],
]);
