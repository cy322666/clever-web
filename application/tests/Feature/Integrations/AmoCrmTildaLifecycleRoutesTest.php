<?php

namespace Tests\Feature\Integrations;

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AmoCrmTildaLifecycleRoutesTest extends TestCase
{
    public function test_removed_widget_callbacks_cannot_start_installation_or_disconnect(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        config(['services.amocrm.widgets.tilda.client_id' => 'legacy-test-client']);

        // This integration uses the platform connection, not its removed installer.
        foreach (['install', 'off'] as $event) {
            $this->assertNull(app('router')->getRoutes()->getByName('amocrm.tilda.'.$event));
            $this->assertFalse(method_exists(AuthController::class, $event.'Tilda'));
            foreach (['GET', 'POST'] as $method) {
                $this->json($method, '/api/amocrm/'.$event.'/tilda', [
                    'code' => 'test-only-code', 'referer' => 'example.amocrm.ru',
                ])->assertNotFound();
            }
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
