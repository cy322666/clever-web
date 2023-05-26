<?php

namespace App\Models\Integrations\GetCourse;

abstract class FormNote
{
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
}
