<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class YandexSpeechkitService
{
    public string $apiKey;

    public function transcribeFromUrl(string $recordingUrl): ?string
    {
        $folderId = config('services.yandex_speechkit.folder_id');//TODO
        $language = 'ru-RU';
        $defaultFormat = 'oggopus';
        $defaultSampleRate = 48000;

        $audioResponse = Http::get($recordingUrl);

        if (!$audioResponse->ok()) {
            return null;
        }

//        $audioFormat = $format ?: $defaultFormat;
//        $rate = $sampleRate ?: $defaultSampleRate;
        $contentType = $this->resolveContentType($defaultFormat);

        $response = Http::withToken($this->apiKey)
            ->withBody($audioResponse->body(), $contentType)
            ->post('https://stt.api.cloud.yandex.net/speech/v1/stt:recognize', [
                'folderId' => $folderId,
                'lang' => $language,
                'format' => $defaultFormat,
                'sampleRateHertz' => $defaultSampleRate,
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
