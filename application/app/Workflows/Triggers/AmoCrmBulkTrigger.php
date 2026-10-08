<?php

namespace App\Workflows\Triggers;

/** Explicit entry point for selected entities in amoCRM lists. */
class AmoCrmBulkTrigger extends ManualTrigger
{
    public static function type(): string
    {
        return 'amo-bulk';
    }

    public static function name(): string
    {
        return 'Массовое действие';
    }

    public static function description(): string
    {
        return 'Запуск из списка сделок, контактов или компаний amoCRM. Отдельный запуск для каждой выбранной сущности.';
    }

    public static function icon(): string
    {
        return 'heroicon-o-rectangle-stack';
    }

    public function shouldTrigger(array $config, mixed $subject, array $context = []): bool
    {
        return false;
    }
}
