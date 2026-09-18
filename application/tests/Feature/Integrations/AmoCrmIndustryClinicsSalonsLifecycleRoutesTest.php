<?php

namespace Tests\Feature\Integrations;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AmoCrmIndustryClinicsSalonsLifecycleRoutesTest extends TestCase
{
    public function test_install_redirect_is_available_and_does_not_log_authorization_code(): void
    {
        Log::spy();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 501]])]);
        config([
            'widget_lifecycle.telegram.token' => 'test-token',
            'widget_lifecycle.telegram.chat_id' => '-100123',
        ]);

        $response = $this->get('/api/amocrm/industry-clinics-salons/redirect?code=secret-code&referer=example.amocrm.ru&platform=1');

        $response
            ->assertOk()
            ->assertSee('Решение установлено')
            ->assertSee('Клиники и салоны');

        Log::shouldHaveReceived('info')->with(
            'amocrm.industry-clinics-salons.install received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.code') === '[received]'
                && data_get($context, 'payload.referer') === 'example.amocrm.ru'),
        )->once();
        Log::shouldHaveReceived('info')->with(
            'amocrm.widget-lifecycle.telegram sent',
            Mockery::on(fn (array $context): bool => $context['event'] === 'install'
                && $context['widget'] === 'industry-clinics-salons'
                && $context['message_id'] === 501),
        )->once();
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
            && $request['chat_id'] === '-100123'
            && str_contains($request['text'], '🟢 Виджет установлен')
            && str_contains($request['text'], 'Домен: example.amocrm.ru')
            && ! str_contains($request['text'], 'secret-code'));
    }

    public function test_off_hook_accepts_amocrm_get_request_and_redacts_signature(): void
    {
        Log::spy();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 502]])]);
        config([
            'widget_lifecycle.telegram.token' => 'test-token',
            'widget_lifecycle.telegram.chat_id' => '-100123',
        ]);

        $response = $this->get('/api/amocrm/industry-clinics-salons/off?account_id=123&client_uuid=client-id&signature=secret');

        $response
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        Log::shouldHaveReceived('info')->with(
            'amocrm.industry-clinics-salons.off received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.account_id') === '123'
                && data_get($context, 'payload.client_uuid') === 'client-id'
                && data_get($context, 'payload.signature') === '[received]'),
        )->once();
        Log::shouldHaveReceived('info')->with(
            'amocrm.widget-lifecycle.telegram sent',
            Mockery::on(fn (array $context): bool => $context['event'] === 'off'
                && $context['widget'] === 'industry-clinics-salons'
                && $context['message_id'] === 502),
        )->once();
        Http::assertSent(fn ($request): bool => str_contains($request['text'], '🔴 Виджет отключён')
            && str_contains($request['text'], 'Аккаунт: 123')
            && str_contains($request['text'], 'ID интеграции: client-id')
            && ! str_contains($request['text'], 'secret'));
    }
}
