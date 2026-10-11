<?php

namespace App\Services\Workflows;

/** Read-only selectors; expression paths never execute PHP or JavaScript. */
final class WorkflowValuePath
{
    public static function get(mixed $value, string $path, mixed $default = null): mixed
    {
        if ($path === '') return $value;
        foreach (explode('.', $path) as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } elseif (in_array($part, ['first', 'first()', 'last', 'last()'], true) && is_array($value)) {
                if ($value === []) return $default;
                // A single unwrapped entity is already its first/last item.
                if (array_is_list($value)) $value = $value[str_starts_with($part, 'first') ? 0 : array_key_last($value)];
            } else {
                return $default;
            }
        }
        return $value;
    }
}
