<?php

namespace App\Services\Vetmanager;

use App\Models\Integrations\Vetmanager\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class VetmanagerApiClient
{
    private string $baseUrl;

    private string $webhookModel = 'webHook';

    private ?string $legacyWebhookGroupId = null;

    public function __construct(private readonly Setting $setting)
    {
        $this->baseUrl = self::normalizeBaseUrl((string) $setting->base_url);

        if (blank($setting->api_key)) {
            throw new RuntimeException('Не указан REST API ключ Vetmanager.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function admission(string $admissionId): array
    {
        if ($admissionId === '' || preg_match('/^[A-Za-z0-9._-]+$/', $admissionId) !== 1) {
            throw new RuntimeException('Некорректный ID приема Vetmanager.');
        }

        $response = $this->request('GET', '/rest/api/Admission/'.rawurlencode($admissionId));
        $admission = data_get($response, 'data.admission');

        if (is_array($admission) && array_is_list($admission)) {
            $admission = $admission[0] ?? null;
        }

        if (! is_array($admission) || (string) ($admission['id'] ?? '') !== $admissionId) {
            throw new RuntimeException('Vetmanager не вернул данные указанного приема.');
        }

        return $admission;
    }

    public function ping(): void
    {
        $this->request('GET', '/rest/api/Admission', query: ['limit' => 1, 'offset' => 0]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function webhooks(): array
    {
        try {
            $response = $this->request('GET', '/rest/api/webHook', query: ['limit' => 100, 'offset' => 0]);
            $this->webhookModel = 'webHook';

            return $this->modelItems($response, ['webHook', 'webhook']);
        } catch (RuntimeException $exception) {
            if (! in_array($exception->getCode(), [404, 405], true)) {
                throw $exception;
            }
        }

        return $this->legacyWebhooks();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createWebhook(array $payload): array
    {
        if ($this->webhookModel === 'ComboManualItem') {
            $payload['combo_manual_id'] = ctype_digit((string) $this->legacyWebhookGroupId)
                ? (int) $this->legacyWebhookGroupId
                : $this->legacyWebhookGroupId;
            $response = $this->request('POST', '/rest/api/ComboManualItem', $payload);

            return $this->firstModelItem($response, ['comboManualItem']);
        }

        $response = $this->request('POST', '/rest/api/webHook', $payload, plainJson: true);

        return $this->firstModelItem($response, ['webHook', 'webhook']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateWebhook(string $id, array $payload): array
    {
        if ($this->webhookModel === 'ComboManualItem') {
            $payload['combo_manual_id'] = ctype_digit((string) $this->legacyWebhookGroupId)
                ? (int) $this->legacyWebhookGroupId
                : $this->legacyWebhookGroupId;
            $response = $this->request('PUT', '/rest/api/ComboManualItem/'.rawurlencode($id), $payload);

            return $this->updatedModelItem($response, ['comboManualItem'], $id);
        }

        $response = $this->request('PUT', '/rest/api/webHook/'.rawurlencode($id), $payload, plainJson: true);

        return $this->updatedModelItem($response, ['webHook', 'webhook'], $id);
    }

    public static function normalizeBaseUrl(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new RuntimeException('Не указан адрес Vetmanager.');
        }

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if ($scheme !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('Укажите HTTPS-адрес кабинета Vetmanager без порта и учетных данных.');
        }

        $allowedSuffixes = config('services.vetmanager.allowed_host_suffixes', [
            '.vetmanager.ru',
            '.vetmanager2.ru',
            '.vetmanager.cloud',
            '.vetmanager2.cloud',
        ]);

        $allowed = collect(is_array($allowedSuffixes) ? $allowedSuffixes : [])
            ->filter(fn (mixed $suffix): bool => is_string($suffix) && $suffix !== '')
            ->contains(function (string $suffix) use ($host): bool {
                $suffix = mb_strtolower(trim($suffix));

                return $host === ltrim($suffix, '.') || str_ends_with($host, $suffix);
            });

        if (! $allowed) {
            throw new RuntimeException('Адрес должен принадлежать домену Vetmanager.');
        }

        return 'https://'.$host;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $payload = [],
        array $query = [],
        bool $plainJson = false,
    ): array {
        $options = [];
        $request = $this->pendingRequest();

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($payload !== []) {
            if ($plainJson) {
                $request = $request->withBody(
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'text/plain',
                );
            } else {
                $options['json'] = $payload;
            }
        }

        $response = $request->send(strtoupper($method), $this->baseUrl.'/'.ltrim($path, '/'), $options);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Vetmanager API вернул HTTP %d для %s %s.',
                $response->status(),
                strtoupper($method),
                $path,
            ), $response->status());
        }

        if ($response->status() === 204 || $response->body() === '') {
            return [];
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Vetmanager API вернул некорректный JSON.');
        }

        if (($body['success'] ?? true) === false) {
            throw new RuntimeException('Vetmanager API отклонил запрос: '.trim((string) ($body['message'] ?? 'unknown error')));
        }

        return $body;
    }

    private function pendingRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'X-REST-API-KEY' => (string) $this->setting->api_key,
                'X-REST-TIME-ZONE' => (string) ($this->setting->timezone ?: 'Europe/Moscow'),
            ])
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(20);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function legacyWebhooks(): array
    {
        $filter = json_encode([[
            'property' => 'name',
            'value' => 'services_for_hooks',
            'operator' => '=',
        ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $groups = $this->modelItems(
            $this->request('GET', '/rest/api/ComboManualName', query: ['filter' => $filter, 'limit' => 100]),
            ['comboManualName'],
        );
        $group = collect($groups)->first(
            fn (array $item): bool => ($item['name'] ?? null) === 'services_for_hooks'
        );

        if (! is_array($group) || blank($group['id'] ?? null)) {
            throw new RuntimeException('Vetmanager не вернул группу настроек вебхуков.');
        }

        $this->webhookModel = 'ComboManualItem';
        $this->legacyWebhookGroupId = (string) $group['id'];
        $filter = json_encode([[
            'property' => 'combo_manual_id',
            'value' => $this->legacyWebhookGroupId,
            'operator' => '=',
        ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->modelItems(
            $this->request('GET', '/rest/api/ComboManualItem', query: [
                'filter' => $filter,
                'limit' => 100,
                'offset' => 0,
            ]),
            ['comboManualItem'],
        );
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $keys
     * @return array<int, array<string, mixed>>
     */
    private function modelItems(array $response, array $keys): array
    {
        $items = [];

        foreach ($keys as $key) {
            $candidate = data_get($response, 'data.'.$key);

            if (is_array($candidate)) {
                $items = $candidate;
                break;
            }
        }

        if ($items === []) {
            return [];
        }

        if (! array_is_list($items)) {
            $items = [$items];
        }

        return array_values(array_filter($items, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function firstModelItem(array $response, array $keys): array
    {
        $item = $this->modelItems($response, $keys)[0] ?? null;

        if (! is_array($item) || blank($item['id'] ?? null)) {
            throw new RuntimeException('Vetmanager не вернул ID вебхука.');
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function updatedModelItem(array $response, array $keys, string $id): array
    {
        $item = $this->modelItems($response, $keys)[0] ?? [];

        if (blank($item['id'] ?? null)) {
            $item['id'] = $id;
        }

        return $item;
    }
}
