<?php

namespace App\Services\Sqns;

use App\Models\Integrations\Sqns\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Client
{
    private const ALLOWED_BASE_URLS = [
        'https://crm3.sqns.ru',
        'https://crm2.sqns.ru',
    ];

    private ?string $token;

    public function __construct(private readonly Setting $setting)
    {
        $this->token = filled($setting->token) ? (string) $setting->token : null;
    }

    public function connect(string $webhookUrl): array
    {
        $auth = $this->authenticate();
        $token = (string) $this->token;
        $this->registerWebhook($webhookUrl);

        $this->setting->forceFill([
            'token' => $token,
            'webhook_secret' => data_get($auth, 'webhookSecret') ?? data_get($auth, 'data.webhookSecret'),
            'registered_webhook_url' => $webhookUrl,
            'organization_id' => data_get($auth, 'user.orgId') ?? data_get($auth, 'data.user.orgId'),
            'connected_at' => now(),
            'last_error' => null,
        ])->save();

        return $auth;
    }

    public function registerWebhook(string $url): void
    {
        $method = $this->setting->connected_at ? 'patch' : 'post';

        $this->send($method, '/api/v2/hook_settings', ['urls' => [$url]]);
    }

    public function disconnect(string $webhookUrl): void
    {
        $registeredWebhookUrl = filled($this->setting->registered_webhook_url)
            ? (string) $this->setting->registered_webhook_url
            : $webhookUrl;
        $reauthenticated = false;

        try {
            $response = $this->deleteWebhook($registeredWebhookUrl);
        } catch (RequestException $exception) {
            if (! in_array($exception->response->status(), [401, 403], true)) {
                throw $exception;
            }

            $this->authenticate();
            $reauthenticated = true;
            $response = $this->deleteWebhook($registeredWebhookUrl);
        }

        Log::info('SQNS webhook delete accepted.', [
            'setting_id' => $this->setting->id,
            'status' => $response->status(),
            'reauthenticated' => $reauthenticated,
        ]);

        $this->setting->forceFill([
            'token' => null,
            'webhook_secret' => null,
            'webhook_key' => Str::random(48),
            'registered_webhook_url' => null,
            'organization_id' => null,
            'organization_name' => null,
            'connected_at' => null,
            'last_error' => null,
        ])->save();

        $this->token = null;
    }

    private function authenticate(): array
    {
        if (blank($this->setting->email) || blank($this->setting->password)) {
            throw new RuntimeException('Не указан email и/или пароль SQNS.');
        }

        $lastException = null;

        foreach ($this->authenticationBaseUrls() as $baseUrl) {
            try {
                $response = $this->send('post', '/api/v2/auth', [
                    'email' => $this->setting->email,
                    'password' => $this->setting->password,
                ], false, baseUrl: $baseUrl);
            } catch (ConnectionException|RequestException $exception) {
                $lastException = $exception;

                continue;
            }

            $auth = $response->json() ?? [];
            $token = data_get($auth, 'token') ?? data_get($auth, 'data.token');

            if (! is_string($token) || trim($token) === '') {
                $lastException = new RuntimeException('SQNS не вернул токен авторизации.');

                continue;
            }

            $this->token = trim($token);
            $this->setting->api_base_url = $baseUrl;

            return $auth;
        }

        throw new RuntimeException(
            'Не удалось авторизоваться в SQNS. Проверьте email и пароль.',
            previous: $lastException,
        );
    }

    private function deleteWebhook(string $webhookUrl): Response
    {
        return $this->send(
            'delete',
            '/api/v2/hook_settings',
            ['urls' => [$webhookUrl]],
            acceptedStatuses: [404],
        );
    }

    public function listVisits(int $page, string $dateFrom, string $dateTill): array
    {
        return $this->send('get', '/api/v2/visit', [
            'page' => max(1, $page),
            'perPage' => 100,
            'dateFrom' => $dateFrom,
            'dateTill' => $dateTill,
        ])->json() ?? [];
    }

    private function send(
        string $method,
        string $path,
        array $data = [],
        bool $authorized = true,
        array $acceptedStatuses = [],
        ?string $baseUrl = null,
    ): Response {
        if ($authorized && blank($this->token)) {
            throw new RuntimeException('SQNS не подключён: отсутствует токен.');
        }

        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(30)
            ->retry(
                [500, 1000, 2000],
                0,
                fn (Throwable $exception): bool => $this->shouldRetry($exception),
                false,
            );

        if ($authorized) {
            $request = $request->withToken((string) $this->token);
        }

        $method = strtolower($method);
        $url = $this->baseUrl($baseUrl).'/'.ltrim($path, '/');
        $response = $method === 'get'
            ? $request->get($url, $data)
            : $request->send(strtoupper($method), $url, ['json' => $data]);

        if (! in_array($response->status(), $acceptedStatuses, true)) {
            $response->throw();
        }

        return $response;
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }

    private function authenticationBaseUrls(): array
    {
        $configured = rtrim(trim((string) $this->setting->api_base_url), '/');

        return array_values(array_unique(array_filter(
            [$configured, ...self::ALLOWED_BASE_URLS],
            fn (string $url): bool => in_array($url, self::ALLOWED_BASE_URLS, true),
        )));
    }

    private function baseUrl(?string $baseUrl = null): string
    {
        $url = rtrim(trim($baseUrl ?? (string) $this->setting->api_base_url), '/');

        if (! in_array($url, self::ALLOWED_BASE_URLS, true)) {
            throw new RuntimeException('Недопустимый адрес API SQNS.');
        }

        return $url;
    }
}
