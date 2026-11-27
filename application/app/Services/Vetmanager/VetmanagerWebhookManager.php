<?php

namespace App\Services\Vetmanager;

use App\Models\Integrations\Vetmanager\Setting;
use RuntimeException;

class VetmanagerWebhookManager
{
    public function verify(Setting $setting): void
    {
        $url = rtrim($setting->webhookUrl(), '/');
        $webhooks = (new VetmanagerApiClient($setting))->webhooks();
        $webhook = collect($webhooks)->first(
            fn (array $item): bool => (string) ($item['id'] ?? '') === (string) $setting->webhook_id,
        );

        if ($url === '' || ! $webhook || rtrim((string) ($webhook['value'] ?? ''), '/') !== $url) {
            throw new RuntimeException('В Ветменеджере не найден вебхук этой интеграции.');
        }

        if ((int) ($webhook['is_active'] ?? 0) !== 1) {
            throw new RuntimeException('Вебхук отключен в Ветменеджере.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function synchronize(Setting $setting): array
    {
        if (! $setting->active) {
            throw new RuntimeException('Сначала включите интеграцию с Ветменеджером.');
        }

        $account = $setting->account()->first();

        if (! $account?->active) {
            throw new RuntimeException('Сначала подключите amoCRM.');
        }

        if (! $setting->isReadyToSync()) {
            throw new RuntimeException('Настройте все этапы amoCRM для событий Ветменеджера.');
        }

        $url = $setting->webhookUrl();

        if ($url === '') {
            throw new RuntimeException('Не удалось сформировать URL вебхука.');
        }

        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Для вебхука Ветменеджера требуется публичный HTTPS-адрес приложения.');
        }

        $client = new VetmanagerApiClient($setting);
        $webhooks = $client->webhooks();
        $existing = collect($webhooks)->first(function (array $webhook) use ($setting, $url): bool {
            return (filled($setting->webhook_id) && (string) ($webhook['id'] ?? '') === (string) $setting->webhook_id)
                || rtrim((string) ($webhook['value'] ?? ''), '/') === rtrim($url, '/');
        });

        $timezone = blank($setting->webhook_id) ? $client->clinicTimezone() : null;

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
            'timezone' => $timezone ?? $setting->timezone,
        ])->save();

        return $webhook;
    }
}
