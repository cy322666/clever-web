<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestTelegramAlert extends Command
{
    protected $signature = 'platform:telegram-test
        {--level=info : info, warning или critical}
        {--title=Тестовый пуш платформы : Заголовок сообщения}
        {--message=Telegram-канал технических пушей работает. : Текст сообщения}';

    protected $description = 'Send a test platform technical alert to Telegram';

    public function handle(): int
    {
        $level = (string)$this->option('level');

        if (!in_array($level, ['info', 'warning', 'critical'], true)) {
            $this->error('level должен быть info, warning или critical.');

            return self::FAILURE;
        }

        if (!config('alerts.enabled', true)) {
            $this->error('Алерты выключены: ALERTS_ENABLED=false.');

            return self::FAILURE;
        }

        if (!config('alerts.channels.telegram.enabled', true)) {
            $this->error('Telegram-алерты выключены: ALERTS_TG_ENABLED=false.');

            return self::FAILURE;
        }

        if (blank(config('alerts.channels.telegram.token')) || blank(config('alerts.channels.telegram.chat_id'))) {
            $this->error('Не настроены TELEGRAM_ALERTS_TOKEN / TELEGRAM_ALERTS_CHAT_ID.');

            return self::FAILURE;
        }

        $title = (string)$this->option('title');
        $message = (string)$this->option('message');

        $text = implode("\n", [
            '[' . strtoupper($level) . '] ' . $title,
            $message,
            'environment: ' . app()->environment(),
            'app_url: ' . config('app.url'),
            'sent_at: ' . now()->timezone(config('app.timezone'))->format('d.m.Y H:i:s'),
        ]);

        $response = Http::timeout(10)->asForm()->post(
            'https://api.telegram.org/bot' . config('alerts.channels.telegram.token') . '/sendMessage',
            [
                'chat_id' => config('alerts.channels.telegram.chat_id'),
                'text' => $text,
            ],
        );

        if (!$response->successful() || $response->json('ok') !== true) {
            $this->error('Telegram API не принял сообщение.');
            $this->line('HTTP: ' . $response->status());
            $this->line('Ошибка: ' . ($response->json('description') ?: $response->body()));

            return self::FAILURE;
        }

        $this->info('Тестовый Telegram-пуш отправлен. Message ID: ' . $response->json('result.message_id'));

        return self::SUCCESS;
    }
}
