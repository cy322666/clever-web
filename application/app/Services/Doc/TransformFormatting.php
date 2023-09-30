<?php

namespace App\Services\Doc;

use Carbon\Carbon;

class TransformFormatting
{
    //используются с ид полей в шаблоне, через |
    public static array $staticVariables = [
        'date' => [
            'Y-m-d',
            'Y.m.d',
            'word'
        ],
        'numeric' => [

        ],
        'word' => [
            'ucfirst',
            'strtoupper',
        ],
    ];

    public static function matchTypeAndFormat(string $key, string $value)
    {
        return match ($key) {
            'date' => Carbon::parse($value)->format('')
        };
    }

    public static function formatDate(string $key, string $value) : string
    {
        return match ($key) {
            'Y-m-d' => Carbon::parse($value)->format('Y-m-d'),
            'Y.m.d' => Carbon::parse($value)->format('Y.m.d'),
            default => $value,
        };
    }

    public static function formatNumeric($key, $value) : string
    {

    }

    public static function formatWord($key, $value) : string
    {

    }
}
