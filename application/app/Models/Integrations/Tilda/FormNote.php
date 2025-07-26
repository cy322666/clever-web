<?php

namespace App\Models\Integrations\Tilda;

use Illuminate\Support\Facades\Log;

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

            try {

                if (!is_array($key) && !is_array($value))

                    $note = array_merge($note, ['- '.$key.' : '.$value]);

            } catch (\Throwable $e) {

                Log::warning($e->getMessage(), [$key, $value]);
            }
        }

        return implode("\n", $note);
    }


    public static function products(array $products): string
    {
        $note = [
            "Информация о товарах",
            '----------------------',
        ];

        $value = '';

        foreach ($products as $product) {

            foreach ((array)$product as $key => $value) {

                try {

                    $value .= str_replace(['\u0026quot;', '&quot;'], '"', $product->value)."\n";;

                    $note = array_merge($note, ['- '.$key.' : '.$value]);

                } catch (\Throwable $e) {

                    Log::warning($e->getMessage(), [$key, $value]);
                }
            }

            $note = array_merge($note, ['- - -']);
        }

        return implode("\n", $note);
    }
}
