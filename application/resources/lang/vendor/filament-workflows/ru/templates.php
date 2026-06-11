<?php

$fallback = require base_path('vendor/leek/filament-workflows/resources/lang/en/templates.php');

return array_replace_recursive($fallback, [
    'built_in' => [
        'send_welcome_email' => [
            'name' => 'Приветственное письмо',
            'description' => 'Автоматически отправляет приветственное письмо при создании новой записи.',
            'variables' => [
                'model_class' => ['label' => 'Модель для отслеживания'],
                'email_subject' => [
                    'label' => 'Тема письма',
                    'default' => 'Добро пожаловать!',
                ],
                'email_body' => [
                    'label' => 'Текст письма',
                    'default' => 'Спасибо, что присоединились к нам!',
                ],
            ],
            'actions' => [
                'send_welcome_email' => ['name' => 'Отправить приветственное письмо'],
            ],
        ],
        'notify_on_status_change' => [
            'name' => 'Уведомление при смене статуса',
            'description' => 'Отправляет уведомление, когда у записи меняется статус.',
            'variables' => [
                'model_class' => ['label' => 'Модель для отслеживания'],
                'status_field' => ['label' => 'Поле статуса'],
                'target_status' => ['label' => 'Целевой статус'],
                'notification_title' => [
                    'label' => 'Заголовок уведомления',
                    'default' => 'Статус обновлен',
                ],
            ],
            'actions' => [
                'send_status_notification' => [
                    'name' => 'Отправить уведомление о статусе',
                    'body' => 'Статус изменен на {{ target_status }}.',
                ],
            ],
        ],
        'sync_to_external_api' => [
            'name' => 'Синхронизация с внешним API',
            'description' => 'Автоматически отправляет данные во внешний API при обновлении записи.',
            'variables' => [
                'model_class' => ['label' => 'Модель для отслеживания'],
                'api_url' => ['label' => 'URL API'],
                'api_method' => ['label' => 'HTTP-метод'],
            ],
            'actions' => [
                'sync' => ['name' => 'Синхронизировать с внешним API'],
            ],
        ],
        'daily_report' => [
            'name' => 'Ежедневный отчет',
            'description' => 'Автоматически формирует и отправляет ежедневный сводный отчет.',
            'variables' => [
                'recipient_email' => ['label' => 'Email получателя'],
                'report_title' => [
                    'label' => 'Название отчета',
                    'default' => 'Ежедневный сводный отчет',
                ],
                'schedule_time' => ['label' => 'Время отправки (24ч)'],
            ],
            'actions' => [
                'generate_report_data' => ['name' => 'Сформировать данные отчета'],
                'send_report_email' => [
                    'name' => 'Отправить отчет по email',
                    'body' => 'Ежедневный отчет сформирован в {{ now }}.',
                ],
            ],
        ],
        'archive_old_records' => [
            'name' => 'Архивация старых записей',
            'description' => 'Автоматически архивирует записи, созданные до указанной даты.',
            'variables' => [
                'model_class' => ['label' => 'Модель для архивации'],
                'cutoff_date' => ['label' => 'Архивировать записи до даты'],
                'status_field' => ['label' => 'Поле статуса для обновления'],
                'archive_value' => ['label' => 'Значение статуса архива'],
            ],
            'actions' => [
                'archive_old_records' => ['name' => 'Архивировать старые записи'],
            ],
        ],
    ],
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
