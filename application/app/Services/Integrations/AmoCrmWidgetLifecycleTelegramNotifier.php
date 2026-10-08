<?php

namespace App\Services\Integrations;

use App\Models\Core\Account;
use App\Models\User;
use App\Support\Auth\AccountEmail;
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

        $referer = $this->firstFilled([
            $context['referer'] ?? null,
            data_get($payload, 'referer'),
            data_get($payload, 'account.referer'),
            data_get($payload, 'account.subdomain'),
            data_get($payload, 'subdomain'),
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

        $email = $this->ownerEmail($widget, $context);
        if ($email !== null) {
            $lines[] = 'Почта: '.$email;
        }

        $lines[] = 'Домен: '.($referer !== '' ? $referer : 'не передан');

        if ($event === 'install_failed') {
            $lines[] = 'Причина: '.$this->failureReason($context['exception'] ?? null);
        }

        if (isset($context['updated'])) {
            $lines[] = 'Обновлено подключений: '.(int) $context['updated'];
        }

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

    private function ownerEmail(string $widget, array $context): ?string
    {
        $email = AccountEmail::normalize($context['user_email'] ?? null);
        if (AccountEmail::isValid($email)) {
            return $email;
        }

        // Resolve only stored platform owners, never an email supplied by a callback.
        try {
            if ((int) ($context['user_id'] ?? 0) > 0) {
                $email = User::query()->whereKey((int) $context['user_id'])->value('email');
            } else {
                $domain = $this->domain((string) ($context['referer'] ?? ''));
                if (! preg_match('/^([a-z0-9-]+)\.amocrm\.(ru|com)$/', $domain, $matches)) {
                    return null;
                }
                $owners = Account::query()->where('widget', $widget)->where('subdomain', $matches[1])
                    ->where(fn ($query) => $query->where('zone', $matches[2])
                        ->when($matches[2] === 'ru', fn ($query) => $query->orWhereNull('zone')))
                    ->when(filled($context['client_id'] ?? null), fn ($query) => $query->where('client_id', $context['client_id']))
                    ->pluck('user_id')->unique();
                $email = $owners->count() === 1 ? User::query()->whereKey($owners->first())->value('email') : null;
            }
        } catch (Throwable) {
            return null;
        }

        $email = AccountEmail::normalize($email);

        return AccountEmail::isValid($email) ? $email : null;
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
