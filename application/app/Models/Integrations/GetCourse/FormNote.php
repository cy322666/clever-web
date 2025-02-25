<?php

namespace App\Models\Integrations\GetCourse;

abstract class FormNote
{
    static array $defaultFields = [
        'email',
        'phone',
        'name',
        'fullname',
        'status',
        'utm_medium',
        'utm_content',
        'utm_source',
        'utm_term',
        'utm_campaign',
    ];

    public static function create(Form $form): string
    {
        $note = [
            "Информация о заявке",
            '----------------------',
            ' - Имя : ' . $form->name,
            ' - Телефон : ' . $form->phone,
            ' - Почта : ' . $form->email,
        ];
        return implode("\n", $note);
    }

    public static function fields(Form $form): string
    {
        $body = json_decode($form->body);

        $note = [
            "Доп информация о заявке",
            '----------------------',
        ];

        $noteFields = [];

        foreach ($body as $fieldName => $fieldValue) {

            if (!in_array($fieldName, self::$defaultFields)) {

                $noteFields = array_merge($noteFields, [' - '.$fieldName.': '.$fieldValue."\n"]);
            }
        }

        return implode("\n", $note)."\n" . implode("\n", $noteFields);
    }
}
