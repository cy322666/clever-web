<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        $referer = $this->domain($referer);
        $lines = [
            match ($event) {
                'install' => '🟢 Виджет установлен',
                'install_failed' => '🔴 Не удалось установить виджет',
                default => '🔴 Виджет отключён',
            },
            'Виджет: '.$label,
        ];

        if ($accountId !== '') {
            $lines[] = 'Аккаунт: '.$accountId;
        }

        $lines = array_merge($lines, [
            'Домен: '.($referer !== '' ? $referer : 'не передан'),
            'ID интеграции: '.($clientId !== '' ? $clientId : 'не передан'),
        ]);

        if ($event === 'install_failed') {
            $lines[] = 'Причина: '.$this->failureReason($context['exception'] ?? null);
        }

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

    private function domain(string $referer): string
    {
        $host = parse_url(str_contains($referer, '://') ? $referer : 'https://'.ltrim($referer, '/'), PHP_URL_HOST);

        return is_string($host) && preg_match('/^[a-z0-9.-]+$/i', $host) ? Str::lower($host) : '';
    }

    private function failureReason(?Throwable $exception): string
    {
        // Never forward raw exceptions: HTTP bodies and database errors can contain credentials.
        if ($exception instanceof ValidationException) {
            foreach ($exception->errors()['subdomain'] ?? [] as $error) {
                if (str_starts_with($error, 'Все виджеты аккаунта платформы должны подключаться к одному amoCRM:')) {
                    return 'Аккаунт платформы уже подключён к другой amoCRM.';
                }
            }

            return 'Данные подключения не прошли проверку.';
        }

        if ($exception instanceof ConnectionException) {
            return 'Не удалось связаться с amoCRM.';
        }

        if ($exception instanceof RequestException) {
            return 'amoCRM отклонила запрос подключения (HTTP '.$exception->response->status().').';
        }

        $message = $exception?->getMessage() ?? '';
        if (preg_match('/^amoCRM [a-z0-9-]+ (client_id|client_secret|redirect_uri) is not configured\.$/', $message)) {
            return 'Не настроены параметры OAuth для виджета.';
        }

        return match ($message) {
            'amoCRM authorization code is missing.' => 'Не получен код авторизации amoCRM.',
            'amoCRM did not return OAuth tokens.' => 'amoCRM не вернула токены доступа.',
            'amoCRM account response does not contain account or current user ID.' => 'amoCRM не вернула ID аккаунта или пользователя.',
            'amoCRM installer email is missing or invalid.' => 'В профиле установщика amoCRM отсутствует корректная почта.',
            'Several platform users are linked to this amoCRM account.' => 'Эта amoCRM связана с несколькими пользователями платформы.',
            'amoCRM account is already linked to another platform user.' => 'Эта amoCRM уже подключена к другому пользователю платформы.',
            'Platform user already has another amoCRM account.',
            'Platform user already has another amoCRM account for this widget.' => 'Аккаунт платформы уже подключён к другой amoCRM.',
            'Invalid amoCRM referer.' => 'Получен некорректный домен amoCRM.',
            default => 'Внутренняя ошибка подключения. Подробности в журнале сервера.',
        };
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
