<?php

namespace App\Services\Sqns;

use App\Models\Integrations\Sqns\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class Client
{
    private const ALLOWED_BASE_URLS = [
        'https://crm2.sqns.ru',
        'https://crm3.sqns.ru',
    ];

    private ?string $token;

    public function __construct(private readonly Setting $setting)
    {
        $this->token = filled($setting->token) ? (string) $setting->token : null;
    }

    public function connect(string $webhookUrl): array
    {
        if (blank($this->setting->email) || blank($this->setting->password)) {
            throw new RuntimeException('Укажите email и пароль SQNS.');
        }

        $response = $this->send('post', '/api/v2/auth', [
            'email' => $this->setting->email,
            'password' => $this->setting->password,
        ], false);

        $auth = $response->json() ?? [];
        $token = data_get($auth, 'token') ?? data_get($auth, 'data.token');

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('SQNS не вернул токен авторизации.');
        }

        $this->token = $token;
        $this->registerWebhook($webhookUrl);

        $this->setting->forceFill([
            'token' => $token,
            'webhook_secret' => data_get($auth, 'webhookSecret') ?? data_get($auth, 'data.webhookSecret'),
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

    public function getVisit(int $visitId): array
    {
        $data = $this->send('get', '/api/v2/visit/'.$visitId)->json() ?? [];
        $visit = VisitPayload::extract($data);

        if (! is_array($visit) || VisitPayload::id($visit) !== $visitId) {
            throw new RuntimeException('SQNS вернул некорректные данные визита #'.$visitId.'.');
        }

        return $visit;
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

    private function send(string $method, string $path, array $data = [], bool $authorized = true): Response
    {
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
            );

        if ($authorized) {
            $request = $request->withToken((string) $this->token);
        }

        $method = strtolower($method);
        $url = $this->baseUrl().'/'.ltrim($path, '/');
        $response = $method === 'get'
            ? $request->get($url, $data)
            : $request->send(strtoupper($method), $url, ['json' => $data]);

        $response->throw();

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

    private function baseUrl(): string
    {
        $url = rtrim((string) $this->setting->api_base_url, '/');

        if (! in_array($url, self::ALLOWED_BASE_URLS, true)) {
            throw new RuntimeException('Недопустимый адрес API SQNS.');
        }

        return $url;
    }
}
