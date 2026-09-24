<?php

namespace App\Services\Finder;

use App\Models\Integrations\Finder\Setting;
use App\Services\amoCRM\Client;
use Illuminate\Validation\ValidationException;

class WebhookConnection
{
    public const EVENTS = ['add_message', 'add_outgoing_message'];

    public function signature(Setting $setting): string
    {
        return hash_hmac('sha256', 'finder|'.$setting->id.'|'.$setting->user_id.'|'.$setting->account_id, (string) config('app.key'));
    }

    public function url(Setting $setting): string
    {
        return rtrim((string) (config('workflow-webhooks.public_url') ?: config('app.url')), '/').route('finder.hook', [
            'setting' => $setting->id, 'signature' => $this->signature($setting),
        ], false);
    }

    public function connect(Setting $setting): void
    {
        $account = $setting->amoAccount(false, 'finder');
        if (! $account?->active || (int) $account->user_id !== (int) $setting->user_id) {
            throw ValidationException::withMessages(['connection' => 'Сначала подключите amoCRM.']);
        }
        $setting->forceFill(['account_id' => $account->id, 'connected_at' => null])->save();
        $url = $this->url($setting);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages(['connection' => 'Для вебхука нужен публичный HTTPS адрес платформы.']);
        }
        $client = $this->client($setting);
        $hooks = $client->requestV4('GET', '/api/v4/webhooks');
        $hook = collect(data_get($hooks, '_embedded.webhooks', []))->firstWhere('destination', $url);
        if (! $this->isConnected($hook)) {
            $client->requestV4('POST', '/api/v4/webhooks', ['destination' => $url, 'settings' => self::EVENTS]);
            $hooks = $client->requestV4('GET', '/api/v4/webhooks');
            $hook = collect(data_get($hooks, '_embedded.webhooks', []))->firstWhere('destination', $url);
        }
        if (! $this->isConnected($hook)) {
            throw ValidationException::withMessages(['connection' => 'amoCRM не подтвердил подписку на оба события сообщений.']);
        }
        $setting->forceFill(['connected_at' => now()])->save();
    }

    private function isConnected(?array $hook): bool
    {
        return $hook && empty($hook['disabled']) && ! array_diff(self::EVENTS, $hook['settings'] ?? []);
    }

    protected function client(Setting $setting): Client
    {
        return new Client($setting->account()->firstOrFail());
    }
}
