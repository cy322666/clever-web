<?php

$fallback = require base_path('vendor/leek/filament-workflows/resources/lang/en/commands.php');

return array_replace_recursive($fallback, [
    'install' => [
        'description' => 'Установить Filament Workflows',
        'installing' => 'Установка Filament Workflows...',
        'published_config' => 'Конфиг опубликован',
        'published_migrations' => 'Миграции опубликованы',
        'ran_migrations' => 'Миграции выполнены',
        'success' => 'Filament Workflows установлен',
        'next_steps' => 'Следующие шаги:',
        'step_register_plugin' => 'Зарегистрируйте плагин в Filament panel',
        'step_configure_models' => 'Настройте модели и триггеры',
        'step_visit_workflows' => 'Откройте страницу процессов',
    ],
    'process_scheduled' => [
        'description' => 'Запустить процессы по расписанию',
    ],
    'compute_metrics' => [
        'description' => 'Пересчитать метрики процессов',
    ],
]);
