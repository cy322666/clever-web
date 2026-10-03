<?php

namespace App\Services\Sqns;

final class AmoPrice
{
    public static function normalize(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));

        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }
}
