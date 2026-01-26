<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class YandexSpeechkitService
{
    public function transcribeFromUrl(string $recordingUrl, ?string $format = null, ?int $sampleRate = null): ?string
    {
        $apiKey = config('services.yandex_speechkit.api_key');
        $folderId = config('services.yandex_speechkit.folder_id');
        $language = config('services.yandex_speechkit.language', 'ru-RU');
        $defaultFormat = config('services.yandex_speechkit.format', 'oggopus');
        $defaultSampleRate = config('services.yandex_speechkit.sample_rate', 48000);

        if (!$apiKey || !$folderId) {
            return null;
        }

        $audioResponse = Http::get($recordingUrl);

        if (!$audioResponse->ok()) {
            return null;
        }

        $audioFormat = $format ?: $defaultFormat;
        $rate = $sampleRate ?: $defaultSampleRate;
        $contentType = $this->resolveContentType($audioFormat);

        $response = Http::withToken($apiKey)
            ->withBody($audioResponse->body(), $contentType)
            ->post('https://stt.api.cloud.yandex.net/speech/v1/stt:recognize', [
                'folderId' => $folderId,
                'lang' => $language,
                'format' => $audioFormat,
                'sampleRateHertz' => $rate,
            ]);

        if (!$response->ok()) {
            return null;
        }

        return $response->json('result');
    }

    private function resolveContentType(string $format): string
    {
        return match ($format) {
            'lpcm' => 'audio/x-pcm',
            'mp3' => 'audio/mpeg',
            default => 'audio/ogg',
        };
    }
}
