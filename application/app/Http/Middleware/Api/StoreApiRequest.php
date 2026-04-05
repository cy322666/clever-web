<?php

namespace App\Http\Middleware\Api;

use App\Models\Core\ApiRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StoreApiRequest
{
    private const HIDDEN_KEYS = [
        'password',
        'pass',
        'token',
        'secret',
        'authorization',
        'cookie',
        'api_key',
        'apikey',
        'client_secret',
        'refresh_token',
        'access_token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $response = $next($request);

        try {
            $this->store($request, $response, $startedAt);
        } catch (\Throwable) {
            // Не блокируем основной API-поток из-за логирования запроса.
        }

        return $response;
    }

    private function store(Request $request, Response $response, float $startedAt): void
    {
        $route = $request->route();
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);

        ApiRequest::query()->create([
            'method' => strtoupper($request->method()),
            'path' => '/' . ltrim($request->path(), '/'),
            'route_name' => $route?->getName(),
            'status_code' => (int)$response->getStatusCode(),
            'duration_ms' => max(0, $durationMs),
            'user_uuid' => $this->resolveUserUuid($route?->parameter('user')),
            'ip_address' => $request->ip(),
            'user_agent' => $this->truncate((string)$request->userAgent(), 1000),
            'query_params' => $this->sanitizeData($request->query()),
            'payload' => $this->sanitizeData($request->except(['_token'])),
            'created_at' => now(),
        ]);
    }

    private function resolveUserUuid(mixed $userParameter): ?string
    {
        if (is_string($userParameter) && $userParameter !== '') {
            return $userParameter;
        }

        if (is_object($userParameter) && isset($userParameter->uuid)) {
            return (string)$userParameter->uuid;
        }

        return null;
    }

    private function truncate(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) . '…' : $value;
    }

    private function sanitizeData(mixed $value, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $itemKey => $itemValue) {
                $sanitized[$itemKey] = $this->sanitizeData($itemValue, (string)$itemKey);
            }

            return $sanitized;
        }

        if ($key !== null && $this->isSensitiveKey($key)) {
            return '***';
        }

        if (is_string($value)) {
            return $this->truncate($value, 2000);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return $this->truncate(json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[unserializable]', 2000);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalizedKey = mb_strtolower($key);

        foreach (self::HIDDEN_KEYS as $needle) {
            if (str_contains($normalizedKey, $needle)) {
                return true;
            }
        }

        return false;
    }
}
