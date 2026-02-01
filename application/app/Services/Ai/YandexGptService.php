<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class YandexGptService
{
    public string $iamToken;
    public string $folderId;

    public function generate(string $prompt, string $transcript): string
    {
        if (!$this->iamToken || !$this->folderId) {
            return $transcript;
        }

        $finalPrompt = $prompt !== '' ? $prompt : 'Составь краткое резюме разговора с менеджером для клиента чтобы отправить ему после разговора. Если общения не было, то оставь сообщение Не получилсь с вами связаться';
        $finalPrompt .= "\n\nТранскрипция звонка:\n" . $transcript;

        $payload = [
            'modelUri' => "gpt://{$this->folderId}/yandexgpt/latest",
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

        if (!$result->ok())
            return $transcript;

        return $result->json('result.alternatives.0.message.text', $transcript);
    }
}
