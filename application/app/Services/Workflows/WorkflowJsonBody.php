<?php

namespace App\Services\Workflows;

use InvalidArgumentException;

final class WorkflowJsonBody
{
    public static function parse(mixed $body): array
    {
        if (is_string($body)) {
            try {
                $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new InvalidArgumentException('Некорректное JSON-тело: '.$error->getMessage());
            }
            if (!is_object($decoded)) throw new InvalidArgumentException('JSON-тело должно быть объектом, а не списком.');
            $body = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        }
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new InvalidArgumentException('JSON-тело должно быть объектом.');
        }
        return $body;
    }
}
