<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Telegram
{
    /**
     * @throws GuzzleException
     */
    public static function send(string $msg, string $chatId, string $token, ?array $keyboard, bool $isMarkdown = true)
    {
        if (strlen($msg) >= 4095) {

            $msg = substr($msg, 0, 50);
        }

        $body = [
            "chat_id" => $chatId,
            "text"    => $msg,
        ];

        if ($isMarkdown !== false) {

            $body = array_merge($body, ["parse_mode" => "markdown"]);
        }

        if (count($keyboard) > 0) {

            $body = array_merge($body, ['reply_markup' => json_encode(['inline_keyboard' => [[$keyboard]]])]);
        }

        (new Client())->get('https://api.telegram.org/bot' . $token . '/sendMessage', [
            'query' => $body
        ]);
    }
}
