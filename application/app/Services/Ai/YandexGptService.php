<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class YandexGptService
{
    public string $iamToken;
    public string $folderId;

    public function generate(string $prompt, string $transcript, bool $strict = false): string
    {
        if (!$this->iamToken || !$this->folderId) {
            if ($strict) {
                throw new RuntimeException('Не задан IAM token или folderId для Yandex GPT.');
            }

            return $transcript;
        }

        $finalPrompt = $prompt !== '' ? $prompt : 'Составь краткое резюме разговора с менеджером для клиента чтобы отправить ему после разговора. Если общения не было, то оставь сообщение Не получилсь с вами связаться';
        $finalPrompt .= "\n\nТранскрипция звонка:\n" . $transcript;
        $model = (string)config('services.yandex_gpt.model', 'yandexgpt');

        $payload = [
            'modelUri' => "gpt://{$this->folderId}/{$model}/latest",
            'completionOptions' => [
                'stream' => false,
                'temperature' => 0.5,
                'maxTokens' => 500,
            ],
            'messages' => [
                [
                    'role' => 'user',
                    'text' => $finalPrompt,
                ],
            ],
        ];

        $result = \Illuminate\Support\Facades\Http::timeout(30)
            ->withToken($this->iamToken)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'x-folder-id' => $this->folderId,   // ✅ важно
            ])
            ->post('https://llm.api.cloud.yandex.net/foundationModels/v1/completion', $payload);

        if (!$result->ok()) {
            if ($strict) {
                throw new RuntimeException('Yandex GPT error: ' . $result->status() . ' ' . $result->body());
            }

            return $transcript;
        }

        $responseText = $result->json('result.alternatives.0.message.text');

        if (!is_string($responseText) || trim($responseText) === '') {
            if ($strict) {
                throw new RuntimeException('Yandex GPT вернул пустой ответ.');
            }

            return $transcript;
        }

        return $responseText;
    }
}
