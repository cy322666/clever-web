<?php

namespace App\Workflows\Triggers;

use Leek\FilamentWorkflows\Triggers\ManualTrigger as BaseManualTrigger;

class ManualTrigger extends BaseManualTrigger
{
    public static function name(): string
    {
        return 'Ручной запуск';
    }

    public static function description(): string
    {
        return 'Запуск вручную из редактора или через API. Настройки не нужны.';
    }

    public static function icon(): string
    {
        return 'heroicon-o-play';
    }

    public static function configSchema(): array
    {
        return [];
    }

    public static function defaultConfig(): array
    {
        return [];
    }

    public static function getConfiguredDescription(array $config): string
    {
        return static::description();
    }
}
