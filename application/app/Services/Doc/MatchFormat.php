<?php

namespace App\Services\Doc;

trait MatchFormat
{
    // example - 123123#date|Y-m-d
    public static function matchTypeAndFormat(string $variable, ?string $value = null)
    {
        try {

            if (strripos($variable, '#')) {

                $formats = explode('#', $variable)[1];

                $keyFormat = explode('|', $formats)[0];
                $format    = explode('|', $formats)[1];

            } elseif (strripos($variable, '|')) {

                $keyFormat = explode('|', $variable)[0];
                $format    = explode('|', $variable)[1];

            } elseif (strripos($variable, '@')) {

                $keyFormat = explode('|', $variable)[0];
                $format    = explode('|', $variable)[1];
            }

            return match ($keyFormat) {
                'date' => FormatService::formatDate($format, $value),
                'word' => FormatService::formatWord($format, $value),
                'numeric' => FormatService::formatNumeric($format, $value),
                'standard' => FormatService::formatStandard($format, $value),
            };
        } catch (\Throwable $e) {

            dump($e->getFile().' '.$e->getMessage(), $variable);
        }
    }
}
