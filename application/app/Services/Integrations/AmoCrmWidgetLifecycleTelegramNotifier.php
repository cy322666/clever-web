<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AmoCrmWidgetLifecycleTelegramNotifier
{
    public function notify(
        string $event,
        string $widget,
        array $payload = [],
        array $context = [],
    ): void {
        $token = trim((string) config('widget_lifecycle.telegram.token', ''));
        $chatId = trim((string) config('widget_lifecycle.telegram.chat_id', ''));

        if ($token === '' || $chatId === '') {
            return;
        }

        $widget = Str::lower(trim($widget));
        $widget = $widget !== '' ? $widget : 'amocrm';
        $label = (string) config("widget_lifecycle.labels.{$widget}", Str::headline($widget));

        $accountId = $this->firstFilled([
            $context['account_id'] ?? null,
            data_get($payload, 'account_id'),
            data_get($payload, 'account.id'),
            data_get($payload, 'account.amo_account_id'),
        ]);
        $referer = $this->firstFilled([
            $context['referer'] ?? null,
            data_get($payload, 'referer'),
            data_get($payload, 'account.referer'),
            data_get($payload, 'account.subdomain'),
            data_get($payload, 'subdomain'),
        ]);
        $clientId = $this->firstFilled([
            $context['client_id'] ?? null,
            data_get($payload, 'client_uuid'),
            data_get($payload, 'client_id'),
            data_get($payload, 'client.id'),
        ]);
        $lines = [
            $event === 'install' ? '🟢 Виджет установлен' : '🔴 Виджет отключён',
            'Виджет: '.$label,
            'Аккаунт: '.($accountId !== '' ? $accountId : 'не передан'),
            'Домен: '.($referer !== '' ? $referer : 'не передан'),
            'ID интеграции: '.($clientId !== '' ? $clientId : 'не передан'),
        ];

        if (isset($context['updated'])) {
            $lines[] = 'Обновлено подключений: '.(int) $context['updated'];
        }

        $lines[] = 'Время: '.now()->format('d.m.Y H:i:s');

        $body = [
            'chat_id' => $chatId,
            'text' => implode("\n", $lines),
            'disable_web_page_preview' => true,
        ];

        $messageThreadId = trim((string) config('widget_lifecycle.telegram.message_thread_id', ''));
        if ($messageThreadId !== '') {
            $body['message_thread_id'] = $messageThreadId;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(2)
                ->timeout(3)
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', $body);

            if (! $response->successful() || $response->json('ok') !== true) {
                Log::warning('amocrm.widget-lifecycle.telegram rejected', [
                    'event' => $event,
                    'widget' => $widget,
                    'status' => $response->status(),
                ]);

                return;
            }

            Log::info('amocrm.widget-lifecycle.telegram sent', [
                'event' => $event,
                'widget' => $widget,
                'message_id' => $response->json('result.message_id'),
            ]);
        } catch (Throwable $exception) {
            Log::warning('amocrm.widget-lifecycle.telegram failed', [
                'event' => $event,
                'widget' => $widget,
                'exception' => $exception::class,
            ]);
        }
    }

    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }
}
