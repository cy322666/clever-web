<?php

namespace App\Services\Workflows;

use RuntimeException;

final class WorkflowHttpStatusCodes
{
    /** Additional responses allowed to continue the workflow; 2xx always succeeds. */
    public static function parse(mixed $value): array
    {
        if ($value === null || $value === '') return [];
        if (is_int($value)) $value = (string) $value;
        if (!is_string($value) || strlen($value) > 255) throw new RuntimeException(self::message());
        $value = trim($value);
        if ($value === '') return [];
        if (!preg_match('/^[1-5][0-9]{2}(?:\s*,\s*[1-5][0-9]{2})*$/D', $value)) {
            throw new RuntimeException(self::message());
        }

        return array_values(array_unique(array_map('intval', explode(',', $value))));
    }

    private static function message(): string
    {
        return 'Допустимые коды ответа: укажите коды от 100 до 599 через запятую, например 404, 409 (не более 255 символов).';
    }
}
