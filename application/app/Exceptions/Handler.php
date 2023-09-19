<?php

namespace App\Exceptions;

use App\Services\Telegram;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {

            if (Env::get('APP_ENV') == 'production') {

                $msg = str_replace(['*', '_', '&', '@'], '', substr($e->getMessage(), 0, 300));
                $title = $e->getFile() . ' : ' . $e->getLine();

                Telegram::send(
                    '*Ошибка в коде!* ' . "\n" . "*Где:* $title" . "\n" . "*Текст:* $msg",
                    env('TG_DEBUG_CHAT_ID'),
                    env('TG_DEBUG_TOKEN'),
                    []
                );

//                $msg = str_replace(['*', '_', '&', '@'], '', substr($e->getTraceAsString(), 0, 150));
//
//                Telegram::send(
//                    'Детали'."\n".$msg,
//                    env('TG_DEBUG_CHAT_ID'),
//                    env('TG_DEBUG_TOKEN'),
//                    []
//                );
            }
        });
    }
}
