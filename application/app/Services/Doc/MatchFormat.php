<?php

namespace App\Services\Doc;

use Illuminate\Support\Facades\Log;

trait MatchFormat
{
    // example - 123123#date|Y-m-d
    public static function matchTypeAndFormat(string $variable, ?string $value = null)
    {
        try {

            if (str_contains($variable, '#')) {

                $formats   = explode('#', $variable)[1];

                $keyFormat = explode('|', $formats)[0];
                $format    = explode('|', $formats)[1];

            } elseif (str_contains($variable, '|')) {

                $keyFormat = explode('|', $variable)[0];
                $format    = explode('|', $variable)[1];

            } elseif (str_contains($variable, '@')) {

                $keyFormat = 'standard';
                $format    = str_replace('@', '', $variable);
            }

            return match ($keyFormat) {
                'date' => FormatService::formatDate($format, $value),
                'word' => FormatService::formatWord($format, $value),
                'numeric' => FormatService::formatNumeric($format, $value),
                'standard' => FormatService::formatStandard($format, $value),
            };
        } catch (\Throwable $e) {

            Log::error($e->getFile().' '.$e->getMessage(), [$variable]);
        }
    }
}
