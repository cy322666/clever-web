<?php

namespace App\Services\Doc;

use App\Models\amoCRM\Field;
use App\Models\Integrations\Docs\Setting;
use Carbon\Carbon;
use Ramsey\Uuid\Uuid;

abstract class FormatService
{
    // используются с ид полей в шаблоне, через |
    // либо для статичных полей
    public static array $staticVariables = [
        'date' => [
            'Y-m-d',
            'Y.m.d',
            'word'//
        ],
        'numeric' => [

        ],
        'word' => [
            'ucfirst',
            'strtoupper',
            'ucfirst-1', // обрезать до 1 символа
            'ucfirst-2', // обрезать до 2 символов
            'ucfirst-3', // обрезать до 3 символов
        ],
    ];

    public static function formatDate(string $key, ?string $value = null) : string
    {
        $date = $value ? Carbon::parse($value) : Carbon::now();

        $date->timezone('Europe/Moscow');

        return match ($key) {
            'Y-m-d' => $date->format('Y-m-d'),
            'Y.m.d' => $date->format('Y.m.d'),
            'd'   => $date->format('d'),
            'm'   => $date->format('m'),
            'y'   => $date->format('y'),
            'Y'   => $date->format('Y'),
            'm-ru' => $date->month()->monthName,
            'm-ru-case-r' => Setting::caseMonth($date->monthName, 'r'),
            default => $date->format($key),
        };
    }

    public static function getValue(int $fieldId, array $entities): ?string
    {
        try {
            $field = Field::query()
                ->where('field_id', $fieldId)
                ->first();

            if ($field)
                return $entities[$field->entity_type]->customFields->byId($field->field_id)->getValue();

        } catch (\Throwable $e) {

            dump($e->getMessage(), $field ?? null, $fieldId);
        }

        return null;
    }

    public static function getValueStandard(string $fieldKey, array $entities): ?string
    {
        return match ($fieldKey) {
            'lead_name' => $entities['leads']->name,
            'contact_name' => $entities['contacts']->name,
            'phone' => $entities['contacts']?->cf('Телефон')->getValue(),
            'email' => $entities['contacts']?->cf('Email')->getValue(),
            'uuid' => Uuid::uuid6(),
            default => $fieldKey,
        };
    }

    //получаем из поля шаблона ид
    public static function getFieldId(string $variable) :int
    {
        if (str_contains($variable, '#'))

            return explode('#', $variable)[0];

        if (str_contains($variable, '|'))

            return explode('|', $variable)[0];

        return (int)$variable;
    }

    public static function formatNumeric(string $key, string $value) : string
    {
        // из цифр в слова
    }

    public static function formatWord(string $key, ?string $value) : string
    {
        return match ($key) {
            'ucfirst' => ucfirst($value),
            'ucfirst-1' => mb_substr($value, 0, 1),
            'ucfirst-2' => mb_substr($value, 0, 2),
            'ucfirst-3' => mb_substr($value, 0, 3),
            'strtoupper' => strtoupper($value),
            default => $value,
        };
    }

    public static function formatStandard(string $key, ?string $value) : string
    {
        return match ($key) {
            'lead_name' => ucfirst($value),
            'phone' => mb_substr($value, 0, 1),
            'email' => mb_substr($value, 0, 2),
            'contact_name' => mb_substr($value, 0, 3),
            default => $value,
        };
    }
}
