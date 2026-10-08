<?php

namespace App\Services\Workflows;

use RuntimeException;

final class WorkflowHttpBody
{
    public const FORMATS = ['json' => 'JSON', 'form' => 'Форма (x-www-form-urlencoded)'];

    public static function format(array $config): string
    {
        $format = $config['body_format'] ?? 'json';
        if (!is_string($format) || !array_key_exists($format, self::FORMATS)) {
            throw new RuntimeException('Выберите формат тела запроса: JSON или форма.');
        }
        return $format;
    }

    public static function form(mixed $fields): string
    {
        if (!is_array($fields)) throw new RuntimeException('Параметры формы должны быть списком полей.');
        $pairs = [];
        $length = 0;
        foreach ($fields as $field) {
            $name = is_array($field) ? ($field['name'] ?? null) : null;
            if ((!is_string($name) && !is_int($name)) || trim((string) $name) === '') {
                throw new RuntimeException('Укажите название каждого параметра формы.');
            }
            $value = $field['value'] ?? '';
            if ((!is_scalar($value) && $value !== null) || (is_float($value) && !is_finite($value))) {
                throw new RuntimeException('Значение параметра формы должно быть текстом или числом, а не массивом или объектом.');
            }
            if (is_bool($value)) $value = $value ? '1' : '0';
            // Encode each pair separately: repeated names and names such as tags[] are preserved.
            $pair = urlencode((string) $name).'='.urlencode((string) ($value ?? ''));
            $length += strlen($pair) + ($pairs === [] ? 0 : 1);
            if ($length > 1048576) throw new RuntimeException('Тело запроса превышает 1 МБ.');
            $pairs[] = $pair;
        }
        return implode('&', $pairs);
    }
}
