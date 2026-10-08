<?php

namespace Tests\Unit\Integrations;

use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AmoCrmWidgetLifecycleTelegramNotifierTest extends TestCase
{
    public function test_all_supported_widget_labels_use_the_same_telegram_entry_point(): void
    {
        config([
            'widget_lifecycle.telegram.token' => 'shared-token',
            'widget_lifecycle.telegram.chat_id' => '-100123',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 700],
        ])]);

        $notifier = app(AmoCrmWidgetLifecycleTelegramNotifier::class);
        $widgets = [
            'workflows' => 'Потоки',
            'import-excel' => 'Импорт Excel',
            'finder' => 'Контроль ответов',
            'sqns' => 'SQNS',
            'yclients' => 'YCLIENTS',
            'vetmanager' => 'Ветменеджер',
            'distribution' => 'Распределение',
            'industry-clinics-salons' => 'Клиники и салоны',
        ];

        foreach ($widgets as $widget => $label) {
            $notifier->notify('install', $widget, [
                'account_id' => 123,
                'referer' => 'example.amocrm.ru',
                'client_uuid' => 'client-id',
                'code' => 'must-not-leak',
                'signature' => 'must-not-leak',
                'email' => 'forged@example.test',
            ], ['user_email' => 'owner@example.test']);

            Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Виджет: '.$label));
        }

        Http::assertSentCount(count($widgets));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/botshared-token/sendMessage'
            && $request['chat_id'] === '-100123'
            && str_contains($request['text'], 'Почта: owner@example.test')
            && ! str_contains($request['text'], 'forged@example.test')
            && ! str_contains($request['text'], 'Аккаунт:')
            && ! str_contains($request['text'], 'ID интеграции:')
            && ! str_contains($request['text'], 'Время:')
            && ! str_contains($request['text'], 'must-not-leak'));
    }

    #[DataProvider('installationErrors')]
    public function test_failure_reason_does_not_expose_exception_or_url_secrets(string $kind, string $expected): void
    {
        config([
            'widget_lifecycle.telegram.token' => 'shared-token',
            'widget_lifecycle.telegram.chat_id' => '-100123',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $exception = match ($kind) {
            'http' => new RequestException(new Response(new PsrResponse(401, [], 'access_token=must-not-leak'))),
            'network' => new ConnectionException('https://example.amocrm.ru?code=must-not-leak'),
            'config' => new \RuntimeException('amoCRM finder client_secret is not configured.'),
            'owner' => new \RuntimeException('amoCRM account is already linked to another platform user.'),
            default => new \RuntimeException('SQL error: access_token=must-not-leak'),
        };

        app(AmoCrmWidgetLifecycleTelegramNotifier::class)->notify('install_failed', 'finder', [], [
            'referer' => 'https://user:must-not-leak@example.amocrm.ru/callback?code=must-not-leak',
            'client_id' => 'finder-client-id',
            'exception' => $exception,
        ]);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Причина: '.$expected)
            && str_contains($request['text'], 'Домен: example.amocrm.ru')
            && ! str_contains($request['text'], 'must-not-leak')
            && ! str_contains($request['text'], 'Аккаунт:'));
    }

    public static function installationErrors(): array
    {
        return [
            'amoCRM HTTP rejection' => ['http', 'amoCRM отклонила запрос подключения (HTTP 401).'],
            'amoCRM unavailable' => ['network', 'Не удалось связаться с amoCRM.'],
            'missing OAuth config' => ['config', 'Не настроены параметры OAuth для виджета.'],
            'different owner' => ['owner', 'Эта amoCRM уже подключена к другому пользователю платформы.'],
            'unexpected error' => ['unknown', 'Внутренняя ошибка подключения. Подробности в журнале сервера.'],
        ];
    }

    public function test_failed_installation_uses_stored_owner_email_not_callback_email(): void
    {
        \Tests\Support\FinderDatabase::prepare();
        \Illuminate\Support\Facades\Schema::table('accounts', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('client_id')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('accounts')->where('id', 1)->update([
            'widget' => 'sqns', 'subdomain' => 'tenant', 'zone' => 'ru', 'client_id' => 'sqns-client',
        ]);
        \Illuminate\Support\Facades\DB::table('accounts')->insert([
            'user_id' => 2, 'widget' => 'sqns', 'subdomain' => 'tenant', 'zone' => 'com', 'client_id' => 'sqns-client',
        ]);
        config(['widget_lifecycle.telegram.token' => 'shared-token', 'widget_lifecycle.telegram.chat_id' => '-100123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        app(AmoCrmWidgetLifecycleTelegramNotifier::class)->notify('install_failed', 'sqns', [
            'email' => 'forged@example.test', 'user_id' => 2,
        ], ['referer' => 'tenant.amocrm.ru', 'client_id' => 'sqns-client']);

        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Почта: finder@example.test')
            && ! str_contains($request['text'], 'other@example.test')
            && ! str_contains($request['text'], 'forged@example.test')
            && ! str_contains($request['text'], 'ID интеграции:')
            && ! str_contains($request['text'], 'Время:'));
    }

    public function test_missing_database_does_not_prevent_failure_notification(): void
    {
        config(['database.default' => 'missing-connection', 'widget_lifecycle.telegram.token' => 'shared-token',
            'widget_lifecycle.telegram.chat_id' => '-100123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        app(AmoCrmWidgetLifecycleTelegramNotifier::class)->notify('install_failed', 'sqns', [], [
            'referer' => 'tenant.amocrm.ru', 'user_id' => 1,
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Не удалось установить виджет')
            && ! str_contains($request['text'], 'Почта:')
            && ! str_contains($request['text'], 'missing-connection'));
    }
}
