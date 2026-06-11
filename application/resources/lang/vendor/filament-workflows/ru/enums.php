<?php

$fallback = require base_path('vendor/leek/filament-workflows/resources/lang/en/enums.php');

return array_replace_recursive($fallback, [
    'failure_strategy' => [
        'stop' => 'Остановить процесс',
        'continue' => 'Перейти к следующему шагу',
        'telegram_report' => 'Остановить и отправить отчёт в Telegram',
        'stop_description' => 'Остановить процесс сразу после ошибки шага',
        'continue_description' => 'Записать ошибку и продолжить следующий шаг',
        'telegram_report_description' => 'Остановить процесс и отправить отчёт об ошибке в Telegram',
    ],
    'metric_period_type' => [
        'rolling_24h' => 'Последние 24 часа',
        'rolling_7d' => 'Последние 7 дней',
        'rolling_30d' => 'Последние 30 дней',
    ],
    'run_status' => [
        'pending' => 'Ожидает',
        'running' => 'Выполняется',
        'paused' => 'На паузе',
        'completed' => 'Завершен',
        'failed' => 'Ошибка',
        'cancelled' => 'Отменен',
    ],
    'step_status' => [
        'pending' => 'Ожидает',
        'running' => 'Выполняется',
        'completed' => 'Завершен',
        'failed' => 'Ошибка',
        'skipped' => 'Пропущен',
    ],
    'template_category' => [
        'communication' => 'Коммуникации',
        'records' => 'Записи',
        'integrations' => 'Интеграции',
        'scheduling' => 'Расписание',
        'custom' => 'Свое',
    ],
    'trigger_event' => [
        'created' => 'Создано',
        'updated' => 'Обновлено',
        'deleted' => 'Удалено',
        'status_changed' => 'Статус изменен',
        'created_description' => 'Срабатывает при создании новой записи',
        'updated_description' => 'Срабатывает при обновлении записи',
        'deleted_description' => 'Срабатывает при удалении записи',
        'status_changed_description' => 'Срабатывает при изменении значения поля',
    ],
    'trigger_type' => [
        'model_event' => 'Событие модели',
        'schedule' => 'Расписание',
        'manual' => 'Ручной запуск',
        'date_condition' => 'Условие по дате',
        'event' => 'Событие',
        'state_transition' => 'Переход состояния',
        'webhook' => 'Webhook',
        'model_event_description' => 'Запускается при создании, обновлении или удалении записи',
        'schedule_description' => 'Запускается по расписанию',
        'manual_description' => 'Запускается пользователем вручную',
        'date_condition_description' => 'Запускается относительно даты в поле',
        'event_description' => 'Запускается при отправке Laravel-события',
        'state_transition_description' => 'Запускается при переходе состояния Spatie Model States',
        'webhook_description' => 'Запускается при получении webhook',
    ],
]);
