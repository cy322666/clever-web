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


    public static function products(array|string $products): string
    {
        $note = [
            "Информация о товарах",
            '----------------------',
        ];

        if (is_string($products)) {
            $products = json_decode($products, true);
        }

        $value = '';

        foreach ($products as $product) {

            foreach ((array)$product as $key => $value) {

                try {
                    if (is_string($value)) {
                        $value = str_replace(['\u0026quot;', '&quot;'], '"', $value);

                        $note = array_merge($note, ['- ' . $key . ' : ' . $value]);
                    }

                    if (is_array($value)) {
                        //[{
                        // option : {}
                        // variant : {}
                        //}]
                        foreach ($value as $option) {
                            $option = str_replace(['\u0026quot;', '&quot;'], '"', $option->option ?? '');
                            $variant = str_replace(['\u0026quot;', '&quot;'], '"', $option->variant ?? '');

                            $note = array_merge($note, ['- ' . $option . ' : ' . $variant]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error(
                        $e->getMessage() . ' ' . $e->getFile() . ' ' . $e->getLine() . ' : ' . json_encode(
                            [$key, $value]
                        )
                    );
                }
            }

            $note = array_merge($note, ['- - -']);
        }

        return implode("\n", $note);
    }
}
