<?php

namespace App\Workflows\Triggers;

/** Explicit entry point for buttons in the amoCRM lead card. */
class AmoCrmButtonTrigger extends ManualTrigger
{
    public static function type(): string
    {
        return 'amo-button';
    }

    public static function name(): string
    {
        return 'Кнопка';
    }

    public static function description(): string
    {
        return 'Запуск кнопкой в карточке сделки amoCRM.';
    }

    public static function icon(): string
    {
        return 'amocrm-button';
    }

    public function shouldTrigger(array $config, mixed $subject, array $context = []): bool
    {
        return false;
    }
}
