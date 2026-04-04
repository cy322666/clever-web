<?php

namespace App\Services\Core;

use App\Services\Telegram;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertService
{
    public static function critical(
        string $title,
        string $message,
        array $context = [],
        ?string $dedupeKey = null,
        ?int $ttlSeconds = null
    ): void {
        self::send('critical', $title, $message, $context, $dedupeKey, $ttlSeconds);
    }

    public static function send(
        string $level,
        string $title,
        string $message,
        array $context = [],
        ?string $dedupeKey = null,
        ?int $ttlSeconds = null
    ): void {
        if (!config('alerts.enabled', true)) {
            return;
        }

        $ttl = $ttlSeconds ?? (int)config('alerts.dedupe_ttl_seconds', 900);

        if ($ttl > 0) {
            $cacheKey = self::buildDedupeCacheKey($level, $title, $message, $dedupeKey);

            if (!Cache::add($cacheKey, now()->timestamp, $ttl)) {
                return;
            }
        }

        $text = self::buildText($level, $title, $message, $context);

        self::sendTelegram($text, $level, $title);
        self::sendMail($text, $level, $title);
    }

    private static function buildDedupeCacheKey(
        string $level,
        string $title,
        string $message,
        ?string $dedupeKey
    ): string {
        $keyPayload = $dedupeKey ?? ($level . '|' . $title . '|' . $message);

        return 'alerts:dedupe:' . sha1($keyPayload);
    }

    private static function buildText(string $level, string $title, string $message, array $context): string
    {
        $lines = [
            '[' . strtoupper($level) . '] ' . $title,
            $message,
        ];

        if ($context !== []) {
            foreach ($context as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $lines[] = (string)$key . ': ' . (string)$value;

                    continue;
                }

                $lines[] = (string)$key . ': ' . json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        return implode("\n", $lines);
    }

    private static function sendTelegram(string $text, string $level, string $title): void
    {
        if (!config('alerts.channels.telegram.enabled', true)) {
            return;
        }

        $token = (string)config('alerts.channels.telegram.token', '');
        $chatId = (string)config('alerts.channels.telegram.chat_id', '');

        if (blank($token) || blank($chatId)) {
            return;
        }

        self::attempt(function () use ($text, $chatId, $token): void {
            Telegram::send($text, $chatId, $token, [], false);
        }, 2, 300, 'telegram', $level, $title);
    }

    private static function attempt(
        callable $callback,
        int $tries,
        int $sleepMs,
        string $channel,
        string $level,
        string $title
    ): void {
        $attempt = 0;

        while ($attempt < $tries) {
            $attempt++;

            try {
                $callback();

                return;
            } catch (\Throwable $e) {
                if ($attempt >= $tries) {
                    Log::warning('Alert send failed', [
                        'channel' => $channel,
                        'level' => $level,
                        'title' => $title,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }

                usleep($sleepMs * 1000);
            }
        }
    }

    public static function warning(
        string $title,
        string $message,
        array $context = [],
        ?string $dedupeKey = null,
        ?int $ttlSeconds = null
    ): void {
        self::send('warning', $title, $message, $context, $dedupeKey, $ttlSeconds);
    }

    private static function sendMail(string $text, string $level, string $title): void
    {
        if (!config('alerts.channels.mail.enabled', false)) {
            return;
        }

        $recipients = config('alerts.channels.mail.to', []);

        if (!is_array($recipients)) {
            $recipients = [];
        }

        $recipients = array_values(
            array_filter(
                array_map(
                    static fn($email): string => trim((string)$email),
                    $recipients,
                )
            )
        );

        if ($recipients === []) {
            return;
        }

        $subject = '[' . strtoupper($level) . '] ' . $title;

        self::attempt(function () use ($recipients, $subject, $text): void {
            Mail::raw($text, function ($mail) use ($recipients, $subject): void {
                $mail->to($recipients)->subject($subject);
            });
        }, 2, 300, 'mail', $level, $title);
    }

    public static function info(
        string $title,
        string $message,
        array $context = [],
        ?string $dedupeKey = null,
        ?int $ttlSeconds = null
    ): void {
        self::send('info', $title, $message, $context, $dedupeKey, $ttlSeconds);
    }
}
