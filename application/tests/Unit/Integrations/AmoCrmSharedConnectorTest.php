<?php

namespace Tests\Unit\Integrations;

use App\Helpers\Traits\SyncAmoCRMPage;
use App\Http\Controllers\Api\AuthController;
use App\Models\Core\Account;
use Tests\TestCase;

class AmoCrmSharedConnectorTest extends TestCase
{
    public function test_yclients_uses_the_shared_connector_by_default(): void
    {
        $reflection = new \ReflectionMethod(AuthController::class, 'shouldUseSharedAmoConnector');
        $reflection->setAccessible(true);

        $this->assertTrue((bool) config('services.amocrm.widgets.yclients.use_shared_connector'));
        $this->assertTrue($reflection->invoke(new AuthController, 'yclients'));
        $this->assertFalse($reflection->invoke(new AuthController, 'tilda'));
    }

    public function test_yclients_uses_the_shared_client_id_on_the_authorization_page(): void
    {
        config([
            'services.amocrm.client_id' => 'shared-client-id',
            'services.amocrm.widgets.yclients.client_id' => 'widget-client-id',
        ]);

        $page = new class
        {
            use SyncAmoCRMPage;

            public function clientId(string $widget, Account $account): string
            {
                return $this->resolveOauthClientId($widget, $account);
            }
        };

        $this->assertSame('shared-client-id', $page->clientId('yclients', new Account));
        $this->assertSame('widget-client-id', $page->clientId('tilda', new Account));
    }
}
