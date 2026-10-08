<?php

namespace App\Services\Vetmanager;

use App\Models\Integrations\Vetmanager\Setting;
use RuntimeException;

class VetmanagerWebhookManager
{
    /**
     * @return array<string, mixed>
     */
    public function synchronize(Setting $setting): array
    {
        if (! $setting->active) {
            throw new RuntimeException('Сначала включите интеграцию Vetmanager.');
        }

        $account = $setting->account()->first();

        if (! $account?->active) {
            throw new RuntimeException('Сначала подключите amoCRM.');
        }

        if (! $setting->targetStatusIds()) {
            throw new RuntimeException('Выберите этап для сделок amoCRM.');
        }

        $url = $setting->webhookUrl();

        if ($url === '') {
            throw new RuntimeException('Не удалось сформировать URL вебхука.');
        }

        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Для webhook Vetmanager требуется публичный HTTPS-адрес приложения.');
        }

        $client = new VetmanagerApiClient($setting);
        $webhooks = $client->webhooks();
        $existing = collect($webhooks)->first(function (array $webhook) use ($setting, $url): bool {
            return (filled($setting->webhook_id) && (string) ($webhook['id'] ?? '') === (string) $setting->webhook_id)
                || rtrim((string) ($webhook['value'] ?? ''), '/') === rtrim($url, '/');
        });

        $payload = [
            'title' => 'CleverCRM: посещения в amoCRM',
            'value' => $url,
            'dop_param1' => (string) $setting->webhook_secret,
            'dop_param2' => '',
            'dop_param3' => implode(',', Setting::WEBHOOK_EVENTS),
            'is_active' => 1,
        ];

        $webhook = $existing
            ? $client->updateWebhook((string) $existing['id'], $payload)
            : $client->createWebhook($payload);

        $setting->forceFill([
            'webhook_id' => (string) $webhook['id'],
            'webhook_synced_at' => now(),
        ])->save();

        return $webhook;
    }
}
