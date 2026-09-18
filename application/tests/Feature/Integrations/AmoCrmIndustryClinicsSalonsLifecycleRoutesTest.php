<?php

namespace Tests\Feature\Integrations;

use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AmoCrmIndustryClinicsSalonsLifecycleRoutesTest extends TestCase
{
    public function test_install_redirect_is_available_and_does_not_log_authorization_code(): void
    {
        Log::spy();

        $response = $this->get('/api/amocrm/industry-clinics-salons/redirect?code=secret-code&referer=example.amocrm.ru');

        $response
            ->assertOk()
            ->assertSee('Решение установлено')
            ->assertSee('Клиники и салоны');

        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.industry-clinics-salons.install received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.code') === '[received]'
                && data_get($context, 'payload.referer') === 'example.amocrm.ru'),
        );
    }

    public function test_off_hook_accepts_amocrm_get_request_and_redacts_signature(): void
    {
        Log::spy();

        $response = $this->get('/api/amocrm/industry-clinics-salons/off?account_id=123&client_uuid=client-id&signature=secret');

        $response
            ->assertOk()
            ->assertExactJson(['ok' => true]);

        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.industry-clinics-salons.off received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.account_id') === '123'
                && data_get($context, 'payload.client_uuid') === 'client-id'
                && data_get($context, 'payload.signature') === '[received]'),
        );
    }
}
