<?php

namespace App\Services\Vetmanager;

use App\Models\Integrations\Vetmanager\Setting;

class WebhookPayloadNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     event_name: string,
     *     admission_id: string,
     *     data: array<string, mixed>,
     *     params: array<string, mixed>,
     *     event_payload: array<string, mixed>
     * }
     */
    public function normalize(array $payload): array
    {
        $body = $this->unwrap($payload);
        $data = $this->nestedArray($body, 'data');
        $params = $this->nestedArray($body, 'params');

        $eventName = trim((string) ($body['name'] ?? $body['event'] ?? $body['eventName'] ?? ''));
        $admissionId = trim((string) ($data['id'] ?? $data['admission_id'] ?? $body['admission_id'] ?? ''));

        return [
            'event_name' => $eventName,
            'admission_id' => $admissionId,
            'data' => $data,
            'params' => $params,
            'event_payload' => $this->sanitize($body),
        ];
    }

    public function isSupported(string $eventName): bool
    {
        return in_array($eventName, Setting::WEBHOOK_EVENTS, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrap(array $payload): array
    {
        foreach (['body', 'payload', 'request'] as $key) {
            $candidate = $this->decodeArray($payload[$key] ?? null);

            if ($candidate !== [] && $this->looksLikeWebhook($candidate)) {
                return $candidate;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function nestedArray(array $body, string $key): array
    {
        $decoded = $this->decodeArray($body[$key] ?? null);

        if ($decoded !== []) {
            return $decoded;
        }

        $result = [];

        foreach ($body as $itemKey => $value) {
            if (! is_string($itemKey)) {
                continue;
            }

            if (preg_match('/^'.preg_quote($key, '/').'\[([^]]+)]$/', $itemKey, $matches) === 1) {
                $result[$matches[1]] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        parse_str($value, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function looksLikeWebhook(array $payload): bool
    {
        return isset($payload['name'])
            || isset($payload['event'])
            || isset($payload['eventName'])
            || isset($payload['data']);
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '***';
        }

        if (! is_array($value)) {
            return $value;
        }

        $result = [];

        foreach ($value as $itemKey => $itemValue) {
            $result[$itemKey] = $this->sanitize($itemValue, (string) $itemKey);
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        return str_contains($key, 'dop_param1')
            || str_contains($key, 'secret')
            || str_contains($key, 'token')
            || str_contains($key, 'api_key');
    }
}
