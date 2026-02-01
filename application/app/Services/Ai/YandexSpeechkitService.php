<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class YandexSpeechkitService
{
    public function __construct(public $iamToken)
    {
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function transcribeFromUrl(string $recordingUrl): ?string
    {
        $folderId = 'b1g3cek7i5mcra3e8mui';
        $language = 'ru-RU';

        // 1) Download
        $audioResponse = Http::timeout(30)->get($recordingUrl);

        if (!$audioResponse->successful()) {
            throw new \RuntimeException(
                'Cannot download audio file: ' . $audioResponse->status() . ' ' . $audioResponse->body()
            );
        }

        $contentType = strtolower((string)$audioResponse->header('Content-Type'));
        $audioBinary = $audioResponse->body();

        if (!is_string($audioBinary) || $audioBinary === '') {
            throw new \RuntimeException('Downloaded audio is empty');
        }

        // 2) Detect by signature (more reliable than headers)
        $sig4 = substr($audioBinary, 0, 4);
        $sig8hex = bin2hex(substr($audioBinary, 0, 8));

        // If we accidentally downloaded HTML/JSON
        if (str_starts_with(ltrim($audioBinary), '<') || str_starts_with(ltrim($audioBinary), '{')) {
            throw new \RuntimeException(
                "URL did not return audio (looks like HTML/JSON). Content-Type={$contentType}, first8hex={$sig8hex}"
            );
        }

        // 3) Normalize to formats SpeechKit understands
        $fileName = null;
        $speechkitFormat = null;
        $binaryToSend = null;

        // OGG container
        if ($sig4 === 'OggS' || str_contains($contentType, 'ogg')) {
            $binaryToSend = $audioBinary;
            $fileName = 'audio.ogg';
            $speechkitFormat = 'oggopus';

            // safety check
            $this->assertOgg($binaryToSend);
            // WAV container (RIFF)
        } elseif ($sig4 === 'RIFF' || str_contains($contentType, 'wav')) {
            // SpeechKit expects raw OGG/Opus or raw PCM (no WAV header).
            // Convert WAV -> OGG/Opus to avoid header issues.
            [$binaryToSend, $fileName, $speechkitFormat] = $this->convertAudioToOggPipe($audioBinary);
            $this->assertOgg($binaryToSend);
            // MP3 (ID3 or frame sync)
        } elseif ($sig4 === 'ID3' || $this->looksLikeMp3($audioBinary) || str_contains(
                $contentType,
                'mpeg'
            ) || str_contains($contentType, 'mp3')) {
            [$binaryToSend, $fileName, $speechkitFormat] = $this->convertAudioToOggPipe($audioBinary);
            $this->assertOgg($binaryToSend);
        } else {
            throw new \RuntimeException(
                "Unsupported audio type. Content-Type={$contentType}, first4='{$sig4}', first8hex={$sig8hex}"
            );
        }

        // 4) SpeechKit STT (raw audio in request body)
        $query = [
            'folderId' => $folderId,
            'lang' => $language,
            'format' => $speechkitFormat, // oggopus or lpcm
        ];
        if ($speechkitFormat === 'lpcm') {
            $query['sampleRateHertz'] = 16000;
        }
        $url = 'https://stt.api.cloud.yandex.net/speech/v1/stt:recognize?' . http_build_query($query);

        $contentType = $speechkitFormat === 'oggopus' ? 'audio/ogg' : 'application/octet-stream';

        $response = Http::timeout(90)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->iamToken,
            ])
            ->withBody($binaryToSend, $contentType)
            ->post($url);

        if (!$response->successful()) {
            throw new \RuntimeException('SpeechKit error: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json('result');
    }

    private function assertOgg(string $bin): void
    {
        if (strlen($bin) < 64) {
            throw new \RuntimeException('OGG binary too small (conversion failed?)');
        }
        if (substr($bin, 0, 4) !== 'OggS') {
            throw new \RuntimeException('Not an OGG container. First8hex=' . bin2hex(substr($bin, 0, 8)));
        }
    }

    private function looksLikeMp3(string $bin): bool
    {
        // MP3 frame sync often starts with 0xFFFB / 0xFFF3 / 0xFFF2
        $b0 = ord($bin[0] ?? "\x00");
        $b1 = ord($bin[1] ?? "\x00");
        return $b0 === 0xFF && in_array($b1 & 0xFE, [0xFA, 0xF2], true);
    }

    /**
     * Convert audio binary -> OGG/Opus binary using ffmpeg via pipes (no temp files).
     * Requires ffmpeg installed in container.
     */
    private function convertAudioToOggPipe(string $audioBinary): array
    {
        // quick check that ffmpeg exists
        if (!trim((string)shell_exec('command -v ffmpeg'))) {
            throw new \RuntimeException('ffmpeg is not installed in this container');
        }

        $cmd = [
            'ffmpeg',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-i',
            'pipe:0',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'libopus',
            '-b:a',
            '24k',
            '-f',
            'ogg',
            'pipe:1',
        ];

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout (ogg)
            2 => ['pipe', 'w'], // stderr
        ];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start ffmpeg process');
        }

        fwrite($pipes[0], $audioBinary);
        fclose($pipes[0]);

        $oggBinary = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($proc);

        if ($code !== 0) {
            throw new \RuntimeException('ffmpeg failed: ' . $err);
        }

        if (!is_string($oggBinary) || $oggBinary === '') {
            throw new \RuntimeException('ffmpeg returned empty output');
        }

        return [$oggBinary, 'audio.ogg', 'oggopus'];
    }
}
