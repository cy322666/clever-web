<?php


namespace App\Services\amoCRM\Models;

use App\Services\amoCRM\Client;

abstract class Notes
{
    public static function add($model, array $values)
    {
        foreach ($values as $key => $value) {

            $array[] = ' - '.$key.' : '.$value;
        }

        $note = $model->createNote($type = 4);
        $note->text = implode("\n", $array ?? []);
        $note->save();

        return $note;
    }

    public static function addOne($model, $text)
    {
        $note = $model->createNote($type = 4);
        $note->text = $text;
        $note->save();

        return $note;
    }
}
