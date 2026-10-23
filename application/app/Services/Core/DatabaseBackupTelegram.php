<?php

namespace App\Services\Core;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class DatabaseBackupTelegram
{
    public function sendLatest(int $notBefore = 0): array
    {
        $token = (string) config('backup.telegram.token');
        $chatId = (string) config('backup.telegram.chat_id');
        $password = (string) config('backup.backup.password');
        if ($token === '' || $chatId === '' || $password === '') {
            throw new RuntimeException('Не настроены Telegram или пароль шифрования резервной копии.');
        }

        $diskName = (string) config('backup.telegram.disk', 'local');
        if (config("filesystems.disks.{$diskName}.driver") !== 'local'
            || ! in_array($diskName, config('backup.backup.destination.disks', []), true)) {
            throw new RuntimeException('Для отправки в Telegram нужна локальная копия резервного архива.');
        }
        $disk = Storage::disk($diskName);
        $name = (string) config('backup.backup.name');
        $file = collect($disk->files($name))
            ->filter(fn (string $file): bool => str_ends_with($file, '.zip') && $disk->lastModified($file) >= $notBefore)
            ->sortByDesc(fn (string $file): int => $disk->lastModified($file))
            ->first();
        if (! $file) {
            throw new RuntimeException('Свежий резервный архив не найден. Старую копию вместо него не отправляли.');
        }

        $path = $disk->path($file);
        $size = $disk->size($file);
        if ($size <= 0) {
            throw new RuntimeException('Резервный архив пуст.');
        }
        $this->verifyEncryption($path, $password);
        $hash = hash_file('sha256', $path);
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Не удалось прочитать резервный архив.');
        }

        $partSize = min(49_000_000, max(1, (int) config('backup.telegram.max_document_bytes', 45_000_000)));
        $parts = (int) ceil($size / $partSize);
        if ($parts > 100) {
            fclose($stream);
            throw new RuntimeException('Резервный архив слишком велик для отправки через Telegram. Копия сохранена на сервере.');
        }

        try {
            $messageIds = [];
            for ($part = 1; $part <= $parts; $part++) {
                $bytes = min($partSize, $size - ($part - 1) * $partSize);
                $partStream = $parts === 1 ? $stream : fopen('php://temp/maxmemory:1048576', 'w+b');
                if ($partStream === false) {
                    throw new RuntimeException('Не удалось подготовить часть резервного архива.');
                }

                try {
                    if ($parts > 1) {
                        if (stream_copy_to_stream($stream, $partStream, $bytes) !== $bytes) {
                            throw new RuntimeException('Не удалось прочитать часть резервного архива.');
                        }
                        rewind($partStream);
                    }
                    $filename = basename($file).($parts > 1 ? '.part'.sprintf('%03d', $part) : '');
                    $caption = "Clever: резервная копия БД\n".basename($file);
                    if ($parts > 1) {
                        $caption .= "\nЧасть {$part}/{$parts}. Соберите части по порядку в один ZIP.";
                    }
                    $payload = [
                        'chat_id' => $chatId,
                        'caption' => $caption."\nЗашифрованный архив. Пароль хранится отдельно.\nSHA-256: ".$hash,
                    ];
                    if ($thread = config('backup.telegram.message_thread_id')) {
                        $payload['message_thread_id'] = $thread;
                    }
                    $response = Http::connectTimeout(10)->timeout(180)->withoutRedirecting()
                        ->attach('document', $partStream, $filename, ['Content-Type' => $parts === 1 ? 'application/zip' : 'application/octet-stream'])
                        ->post('https://api.telegram.org/bot'.$token.'/sendDocument', $payload);
                    if (! $response->successful() || $response->json('ok') !== true
                        || ! $response->json('result.message_id')
                        || (int) $response->json('result.document.file_size') !== $bytes) {
                        throw new RuntimeException('Telegram не подтвердил получение части '.$part.'/'.$parts.' (HTTP '.$response->status().').');
                    }
                    $messageIds[] = (int) $response->json('result.message_id');
                } finally {
                    if ($parts > 1) {
                        fclose($partStream);
                    }
                }
            }

            return ['file' => basename($file), 'size' => $size, 'sha256' => $hash,
                'message_id' => end($messageIds), 'parts' => $parts, 'message_ids' => $messageIds];
        } catch (Throwable $e) {
            // HTTP exception messages can contain the bot token in the request URL.
            throw new RuntimeException($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'Telegram не подтвердил получение части ')
                ? $e->getMessage() : 'Не удалось доставить архив в Telegram. Копия сохранена на сервере.');
        } finally {
            fclose($stream);
        }
    }

    private function verifyEncryption(string $path, string $password): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Не удалось открыть резервный ZIP-архив.');
        }
        try {
            $files = 0;
            $zip->setPassword($password);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! $stat || str_ends_with($stat['name'], '/')) {
                    continue;
                }
                if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_AES_256) {
                    throw new RuntimeException('Отправка незашифрованного архива запрещена. Требуется AES-256.');
                }
                $entry = @$zip->getStream($stat['name']);
                if ($entry === false) {
                    throw new RuntimeException('Пароль не подходит к резервному архиву.');
                }
                fclose($entry);
                $files++;
            }
            if ($files === 0) {
                throw new RuntimeException('Резервный архив не содержит файлов.');
            }
        } finally {
            $zip->close();
        }
    }
}
