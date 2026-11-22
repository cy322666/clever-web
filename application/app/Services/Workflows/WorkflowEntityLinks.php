<?php

namespace App\Services\Workflows;

final class WorkflowEntityLinks
{
    public const SAME_TYPE_ERROR = 'Нельзя связывать сущности одного типа.';

    public static function type(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        return match (strtolower(trim($value))) {
            'lead', 'leads' => 'lead',
            'contact', 'contacts' => 'contact',
            'company', 'companies' => 'company',
            'customer', 'customers' => 'customer',
            default => null,
        };
    }

    public static function sameType(mixed $source, mixed $linked): bool
    {
        $source = self::type($source);
        return $source !== null && $source === self::type($linked);
    }

    public static function options(mixed $source): array
    {
        $options = ['lead' => 'Сделка', 'contact' => 'Контакт', 'company' => 'Компания', 'customer' => 'Покупатель'];
        unset($options[self::type($source) ?? '']);
        return $options;
    }
}
