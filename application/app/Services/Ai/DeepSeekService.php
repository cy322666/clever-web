<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeepSeekService
{
    public function generate(string $prompt, string $transcript, string $apiKey, bool $strict = false): string
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            if ($strict) {
                throw new RuntimeException('Не указан API key DeepSeek.');
            }

            return $transcript;
        }

        $finalPrompt = $prompt !== '' ? $prompt : 'Составь краткое резюме разговора с менеджером для клиента чтобы отправить ему после разговора. Если общения не было, то оставь сообщение Не получилсь с вами связаться';
        $finalPrompt .= "\n\nТранскрипция звонка:\n" . $transcript;

        $payload = [
            'model' => 'deepseek-chat',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $finalPrompt,
                ],
            ],
            'temperature' => 0.5,
            'max_tokens' => 500,
        ];

        $result = Http::timeout(30)
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post('https://api.deepseek.com/chat/completions', $payload);

        if (!$result->ok()) {
            if ($strict) {
                throw new RuntimeException('DeepSeek error: ' . $result->status() . ' ' . $result->body());
            }

            return $transcript;
        }

        $responseText = $result->json('choices.0.message.content');

        if (!is_string($responseText) || trim($responseText) === '') {
            if ($strict) {
                throw new RuntimeException('DeepSeek вернул пустой ответ.');
            }

            return $transcript;
        }

        return $responseText;
    }
}
