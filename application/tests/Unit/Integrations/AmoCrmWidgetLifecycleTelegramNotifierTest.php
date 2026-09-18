<?php

namespace Tests\Unit\Integrations;

use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use Illuminate\Support\Facades\Http;
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
            ]);

            Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Виджет: '.$label));
        }

        Http::assertSentCount(count($widgets));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/botshared-token/sendMessage'
            && $request['chat_id'] === '-100123'
            && ! str_contains($request['text'], 'must-not-leak'));
    }
}
