<?php

namespace App\Services\Core;

use App\Jobs\Core\SendPlatformTechnicalAlert;
use App\Models\App;
use App\Models\Billing\WidgetSubscription;
use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class PlatformTechnicalMonitor
{
    public function registration(User $user): void
    {
        $this->send(
            level: 'info',
            title: 'Новая регистрация',
            lines: [
                'Пользователь зарегистрировался на платформе.',
                'ID: ' . $user->id,
                'Имя: ' . $this->filled($user->name),
                'Email: ' . $this->filled($user->email),
                'Время: ' . $this->dateTime($user->created_at),
            ],
            context: ['user_id' => $user->id],
            dedupeKey: 'platform:registration:' . $user->id,
            ttlSeconds: 86400,
        );
    }

    public function amoConnected(User $user, Account $account, string $widget, string $message = ''): void
    {
        $tokenFingerprint = sha1(implode('|', [
            (string)$account->id,
            (string)$account->subdomain,
            (string)$account->access_token,
            (string)$account->refresh_token,
        ]));

        $this->send(
            level: 'info',
            title: 'amoCRM подключена',
            lines: [
                'Пользователь подключил amoCRM.',
                'Пользователь: ' . $this->userLabel($user),
                'Виджет: ' . $this->widgetTitle($widget),
                'Аккаунт amoCRM: ' . $this->filled($account->subdomain),
                'Зона: ' . $this->filled($account->zone),
                'Сообщение: ' . $this->filled($message),
            ],
            context: [
                'user_id' => $user->id,
                'account_id' => $account->id,
                'widget' => $widget,
                'subdomain' => $account->subdomain,
            ],
            dedupeKey: 'platform:amocrm-connected:' . $account->id . ':' . $tokenFingerprint,
            ttlSeconds: 300,
        );
    }

    public function amoConnectionFailed(?User $user, string $widget, string $message): void
    {
        $this->send(
            level: 'warning',
            title: 'Ошибка подключения amoCRM',
            lines: [
                'Не удалось подключить amoCRM.',
                'Пользователь: ' . ($user ? $this->userLabel($user) : '-'),
                'Виджет: ' . $this->widgetTitle($widget),
                'Ошибка: ' . $this->filled($message),
            ],
            context: [
                'user_id' => $user?->id,
                'widget' => $widget,
            ],
            dedupeKey: 'platform:amocrm-failed:' . ($user?->id ?? 'unknown') . ':' . $widget . ':' . sha1($message),
            ttlSeconds: 900,
        );
    }

    public function subscriptionCreated(WidgetSubscription $subscription): void
    {
        $this->sendSubscriptionEvent('info', 'Подписка создана', $subscription, [
            'На платформе создана подписка.',
        ], 'created');
    }

    public function subscriptionRenewed(WidgetSubscription $subscription, ?string $beforeEndsAt = null): void
    {
        $this->sendSubscriptionEvent('info', 'Подписка продлена', $subscription, [
            'Подписка продлена или снова активирована.',
            'Было до: ' . $this->filled($beforeEndsAt),
        ], 'renewed');
    }

    public function subscriptionExpiring(WidgetSubscription $subscription, int $days): void
    {
        $this->sendSubscriptionEvent('warning', 'Подписка скоро закончится', $subscription, [
            'До окончания доступа осталось дней: ' . max(0, $days),
        ], 'expiring:' . max(0, $days), 86400);
    }

    public function subscriptionExpired(WidgetSubscription $subscription): void
    {
        $this->sendSubscriptionEvent('warning', 'Подписка истекла', $subscription, [
            'Доступ к виджету заблокирован по окончанию срока.',
        ], 'expired');
    }

    public function legacyWidgetExtended(App $app, int $days, ?User $actor = null): void
    {
        $this->sendLegacyWidgetEvent('info', 'Виджет продлён', $app, [
            'Legacy-виджет продлён вручную.',
            'Дней продления: ' . $days,
            'Оператор: ' . ($actor ? $this->userLabel($actor) : '-'),
        ], 'extended:' . $app->updated_at?->timestamp);
    }

    public function legacyWidgetDeactivated(App $app, ?User $actor = null): void
    {
        $this->sendLegacyWidgetEvent('warning', 'Виджет отключён', $app, [
            'Legacy-виджет отключён вручную.',
            'Оператор: ' . ($actor ? $this->userLabel($actor) : '-'),
        ], 'deactivated:' . $app->updated_at?->timestamp);
    }

    private function sendSubscriptionEvent(
        string $level,
        string $title,
        WidgetSubscription $subscription,
        array $extraLines,
        string $eventKey,
        int $ttlSeconds = 86400,
    ): void {
        $subscription->loadMissing(['user', 'plan', 'app']);

        $this->send(
            level: $level,
            title: $title,
            lines: array_merge($extraLines, [
                'Пользователь: ' . ($subscription->user ? $this->userLabel($subscription->user) : 'ID ' . $subscription->user_id),
                'Виджет: ' . $this->widgetTitle((string)$subscription->widget, $subscription->app?->resource_name),
                'Тариф: ' . $this->filled($subscription->plan?->name),
                'Статус: ' . $this->filled(WidgetSubscription::statusOptions()[$subscription->status] ?? $subscription->status),
                'Окончание: ' . $this->date($subscription->ends_at),
                'Льгота: ' . $this->date($subscription->grace_until),
            ]),
            context: [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'app_id' => $subscription->app_id,
                'widget' => $subscription->widget,
                'status' => $subscription->status,
            ],
            dedupeKey: 'platform:subscription:' . $eventKey . ':' . $subscription->id . ':' . $subscription->updated_at?->timestamp,
            ttlSeconds: $ttlSeconds,
        );
    }

    private function sendLegacyWidgetEvent(
        string $level,
        string $title,
        App $app,
        array $extraLines,
        string $eventKey,
    ): void {
        $app->loadMissing('user');

        $this->send(
            level: $level,
            title: $title,
            lines: array_merge($extraLines, [
                'Пользователь: ' . ($app->user ? $this->userLabel($app->user) : 'ID ' . $app->user_id),
                'Виджет: ' . $this->widgetTitle((string)$app->name, $app->resource_name),
                'Статус: ' . $this->filled($app->getStatusLabel()),
                'Окончание: ' . $this->filled($app->expires_tariff_at),
            ]),
            context: [
                'app_id' => $app->id,
                'user_id' => $app->user_id,
                'widget' => $app->name,
                'status' => $app->status,
            ],
            dedupeKey: 'platform:legacy-widget:' . $eventKey . ':' . $app->id,
            ttlSeconds: 86400,
        );
    }

    private function send(
        string $level,
        string $title,
        array $lines,
        array $context,
        ?string $dedupeKey,
        ?int $ttlSeconds,
    ): void {
        if (!config('alerts.enabled', true)) {
            return;
        }

        try {
            SendPlatformTechnicalAlert::dispatch(
                level: $level,
                title: $title,
                message: implode("\n", $lines),
                context: $context,
                dedupeKey: $dedupeKey,
                ttlSeconds: $ttlSeconds,
            );
        } catch (Throwable $e) {
            Log::warning('Platform technical monitor dispatch failed', [
                'title' => $title,
                'level' => $level,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function userLabel(User $user): string
    {
        return sprintf('%s · %s', $this->filled($user->email), 'ID ' . $user->id);
    }

    private function widgetTitle(string $widget, ?string $resourceName = null): string
    {
        try {
            return App::getTitle(Account::normalizeWidget($widget), $resourceName);
        } catch (Throwable) {
            return Account::normalizeWidget($widget);
        }
    }

    private function filled(mixed $value): string
    {
        $value = trim((string)$value);

        return $value !== '' ? $value : '-';
    }

    private function date(mixed $date): string
    {
        if (!$date) {
            return '-';
        }

        try {
            return Carbon::parse($date)->timezone(config('app.timezone'))->format('d.m.Y');
        } catch (Throwable) {
            return (string)$date;
        }
    }

    private function dateTime(mixed $date): string
    {
        if (!$date) {
            return now()->timezone(config('app.timezone'))->format('d.m.Y H:i:s');
        }

        try {
            return Carbon::parse($date)->timezone(config('app.timezone'))->format('d.m.Y H:i:s');
        } catch (Throwable) {
            return (string)$date;
        }
    }
}
