<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\CompleteAmoCrmWidgetInstallation;
use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AmoCrmYClientsLifecycleRoutesTest extends TestCase
{
    public function test_marketplace_callback_queues_dedicated_yclients_installation(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'cache.default' => 'array']);
        Bus::fake();
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class)->shouldReceive('notify')->once();

        $this->postJson(route('amocrm.yclients.install'), ['code' => 'test-code', 'referer' => 'client.amocrm.ru'])
            ->assertStatus(202)->assertJson(['ok' => true, 'status' => 'queued']);
        Bus::assertDispatched(CompleteAmoCrmWidgetInstallation::class, fn ($job) => $job->widget === 'yclients');
    }

    public function test_missing_oauth_code_is_rejected_without_installation(): void
    {
        config(['cache.default' => 'array']);
        Bus::fake();
        $this->getJson(route('amocrm.yclients.install'))->assertStatus(422);
        Bus::assertNothingDispatched();
    }
}
