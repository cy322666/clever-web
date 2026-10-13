<?php

namespace App\Services\Workflows;

use RuntimeException;

final class WorkflowHttpHeaders
{
    /** Accept saved JSON/objects and the editor's name/value rows. */
    public static function parse(mixed $input): array
    {
        if (is_string($input)) {
            try { $input = json_decode($input ?: '{}', true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new RuntimeException('Заголовки: проверьте формат JSON.'); }
        }
        if ($input === null) $input = [];
        if (!is_array($input)) throw new RuntimeException('Заголовки: нужны пары названия и значения.');
        $rows = array_is_list($input) ? $input : array_map(
            fn ($name, $value) => ['name' => $name, 'value' => $value], array_keys($input), array_values($input),
        );
        $headers = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['name'] ?? null) : null;
            $value = is_array($row) ? ($row['value'] ?? null) : null;
            // Preserve the transport/injection guard for both UI formats.
            if (!is_string($name) || !preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name)
                || !is_scalar($value) || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', (string) $value)
                || in_array(strtolower($name), ['host', 'content-length', 'transfer-encoding', 'connection', 'proxy-authorization'], true)) {
                throw new RuntimeException('Недопустимый заголовок запроса.');
            }
            if (isset($seen[strtolower($name)])) throw new RuntimeException('Названия заголовков не должны повторяться.');
            $seen[strtolower($name)] = true;
            $headers[$name] = $value;
        }
        return $headers;
    }
}
