<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class YandexGptService
{
    public string $apiKey;

    public function generate(string $prompt, string $transcript): string
    {
        $apiKey = config('services.yandex_gpt.api_key');
        $folderId = config('services.yandex_gpt.folder_id');
        $model = config('services.yandex_gpt.model', 'yandexgpt');

        if (!$apiKey || !$folderId) {
            return $transcript;
        }

        $response = Http::withToken($apiKey)
            ->post('https://llm.api.cloud.yandex.net/foundationModels/v1/completion', [
                'modelUri' => "gpt://{$folderId}/{$model}",
                'completionOptions' => [
                    'stream' => false,
                    'temperature' => 0.2,
                    'maxTokens' => 2000,
                ],
                'messages' => [
                    [
                        'role' => 'system',
                        'text' => $prompt,
                    ],
                    [
                        'role' => 'user',
                        'text' => $transcript,
                    ],
                ],
            ]);

        if (!$response->ok()) {
            return $transcript;
        }

        return $response->json('result.alternatives.0.message.text', $transcript);
    }
}
