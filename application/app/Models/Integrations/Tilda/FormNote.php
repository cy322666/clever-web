<?php

namespace App\Models\Integrations\Tilda;

abstract class FormNote
{
    public static function create(Form $form): string
    {
        $data = json_decode($form->body, true);

        unset($data['COOKIES']);

        $note = [
            "Информация о заявке",
            '----------------------',
        ];

        foreach ($data as $key => $value) {

            $note = array_merge($note, ['- '.$key.' : '.$value]);
        }

        return implode("\n", $note);
    }
}
