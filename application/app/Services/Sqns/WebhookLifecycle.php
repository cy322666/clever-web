<?php

namespace App\Services\Sqns;

use App\Models\App;
use App\Models\Billing\WidgetSubscription;
use App\Models\Integrations\Sqns\Setting;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class WebhookLifecycle
{
    private const LOCK_SECONDS = 300;

    private const LOCK_WAIT_SECONDS = 30;

    public function shouldDisconnect(Setting $setting): bool
    {
        return $setting->isConnected() && ! $this->canRemainConnected($setting);
    }

    public function isOperational(Setting $setting): bool
    {
        return $setting->isConnected() && $this->canRemainConnected($setting);
    }

    public function connect(Setting $setting): array
    {
        return $this->synchronized((int) $setting->id, function () use ($setting): array {
            $fresh = Setting::query()->with('user')->findOrFail($setting->id);

            if (! $this->canRemainConnected($fresh)) {
                throw new RuntimeException('Сначала включите интеграцию и подключите amoCRM.');
            }

            return (new Client($fresh))->connect($fresh->webhookUrl());
        });
    }

    public function disconnect(Setting $setting): void
    {
        $this->synchronized((int) $setting->id, function () use ($setting): void {
            $fresh = Setting::query()->with('user')->find($setting->id);

            if ($fresh?->isConnected()) {
                (new Client($fresh))->disconnect($fresh->webhookUrl());
            }
        });
    }

    public function disconnectIfNeeded(int $settingId): bool
    {
        return $this->synchronized($settingId, function () use ($settingId): bool {
            $setting = Setting::query()->with('user')->find($settingId);

            if (! $setting || ! $this->shouldDisconnect($setting)) {
                return false;
            }

            (new Client($setting))->disconnect($setting->webhookUrl());

            return true;
        });
    }

    private function canRemainConnected(Setting $setting): bool
    {
        if (! $setting->active || ! (bool) $setting->user?->active) {
            return false;
        }

        $account = $setting->amoAccount(false, 'sqns');

        if (! $account
            || ! $account->active
            || blank($account->access_token)
            || blank($account->refresh_token)
            || blank($account->subdomain)) {
            return false;
        }

        return $this->hasActiveAccess($setting);
    }

    private function hasActiveAccess(Setting $setting): bool
    {
        if (Schema::hasTable('widget_subscriptions')) {
            $subscription = WidgetSubscription::query()
                ->where('user_id', $setting->user_id)
                ->where('widget', 'sqns')
                ->latest('id')
                ->first();

            if ($subscription) {
                return $subscription->isCurrentlyUsable();
            }
        }

        $app = App::query()
            ->where('user_id', $setting->user_id)
            ->where('name', 'sqns')
            ->latest('id')
            ->first();

        if (! $app || (int) $app->status !== App::STATE_ACTIVE) {
            return false;
        }

        if (blank($app->expires_tariff_at)) {
            return true;
        }

        try {
            return ! Carbon::parse($app->expires_tariff_at)->startOfDay()->lt(now()->startOfDay());
        } catch (Throwable) {
            return false;
        }
    }

    private function synchronized(int $settingId, Closure $callback): mixed
    {
        return Cache::lock('sqns:webhook-lifecycle:'.$settingId, self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $callback);
    }
}
