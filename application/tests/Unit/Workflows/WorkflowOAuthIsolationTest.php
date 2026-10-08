<?php

namespace Tests\Unit\Workflows;

use App\Helpers\Traits\SyncAmoCRMPage;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WorkflowManualAmoCrmController;
use App\Http\Controllers\Api\WorkflowWebhookController;
use App\Models\Core\Account;
use App\Models\User;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use App\Services\Workflows\WorkflowConnectionAccess;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowOAuthIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Schema::table('accounts', function (Blueprint $table): void {
            foreach (['zone', 'client_secret', 'redirect_uri', 'code'] as $field) {
                $table->string($field)->nullable();
            }
        });
        config([
            'services.amocrm.client_id' => 'platform-client',
            'services.amocrm.client_secret' => 'platform-secret',
            'services.amocrm.redirect_uri' => 'https://platform.example/shared',
            'services.amocrm.widgets.workflows.client_secret' => 'workflow-secret',
            'services.amocrm.widgets.workflows.redirect_uri' => 'https://platform.example/flow',
        ]);
        Http::preventStrayRequests();
    }

    public function test_missing_own_connection_never_borrows_or_overwrites_another_widget(): void
    {
        $other = $this->account('tilda', 'tilda-client');
        $before = $other->getAttributes();
        $user = User::findOrFail(1);
        $this->assertNull($user->resolveAmoAccountForWidget('workflows'));
        $this->assertFalse(WorkflowConnectionAccess::hasActiveConnection(1));

        $own = $user->resolveAmoAccountForWidget('workflows', true);
        $this->assertNotSame($other->id, $own->id);
        $this->assertSame('workflows', $own->widget);
        $this->assertNull($own->access_token);
        $this->assertNull($own->refresh_token);
        $this->assertSame($before, $other->fresh()->getAttributes());
    }

    public function test_workflow_tokens_are_not_a_fallback_for_other_widgets(): void
    {
        $this->account('workflows', 'workflow-client-id');
        $this->assertNull(User::findOrFail(1)->resolveAmoAccountForWidget('tilda'));
    }

    public function test_disabled_workflow_connection_does_not_fall_back_to_another_widget(): void
    {
        $own = $this->account('workflows', 'workflow-client-id', ['active' => false]);
        $this->account('tilda', 'tilda-client');
        $this->assertSame($own->id, User::findOrFail(1)->resolveAmoAccountForWidget('workflows')->id);
        $this->assertFalse(WorkflowConnectionAccess::hasActiveConnection(1));
    }

    public function test_wrong_client_id_in_workflow_slot_is_not_a_valid_connection(): void
    {
        $own = $this->account('workflows', 'platform-client');
        $this->assertNull(User::findOrFail(1)->resolveAmoAccountForWidget('workflows'));
        $this->assertFalse(WorkflowConnectionAccess::hasActiveConnection(1));
        $this->assertFalse(WorkflowConnectionAccess::accounts()->exists());
        // Reconnect repairs this slot, not a different widget's row.
        $this->assertSame($own->id, User::findOrFail(1)->resolveAmoAccountForWidget('workflows', true)->id);
    }

    public function test_reset_changes_only_the_workflow_slot(): void
    {
        $own = $this->account('workflows', 'workflow-client-id');
        $other = $this->account('tilda', 'tilda-client');
        $before = $other->getAttributes();
        $page = new class { use SyncAmoCRMPage; };
        (new \ReflectionMethod($page, 'resetAmoCrmAccount'))->invoke($page, $own);
        $this->assertSame($before, $other->fresh()->getAttributes());
        $this->assertNull($own->fresh()->refresh_token);
        $this->assertNull(User::findOrFail(1)->resolveAmoAccountForWidget('workflows'));
    }

    public function test_reauthorization_cannot_relabel_old_foreign_oauth_tokens_as_workflow_tokens(): void
    {
        $stale = $this->account('workflows', 'platform-client');
        $valid = $this->account('workflows', 'workflow-client-id');
        $other = $this->account('yclients', 'platform-client');
        $validBefore = $valid->getAttributes();
        $otherBefore = $other->getAttributes();
        WorkflowConnectionAccess::prepareForAuthorization($stale);
        $this->assertNull($stale->access_token);
        $this->assertNull($stale->refresh_token);
        $this->assertFalse($stale->active);
        WorkflowConnectionAccess::prepareForAuthorization($valid);
        WorkflowConnectionAccess::prepareForAuthorization($other);
        $this->assertSame($validBefore, $valid->getAttributes());
        $this->assertSame($otherBefore, $other->getAttributes());
    }

    public function test_missing_own_connection_returns_a_clear_action_error_without_sending_a_request(): void
    {
        $this->account('tilda', 'tilda-client');
        $context = (new WorkflowContext)->setTriggeredBy(1);
        $result = app(WorkflowAmoCrmActionExecutor::class)->execute('amocrm_get_contact', ['contact_id' => 42], $context);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Подключите виджет «Потоки»', $result['error']);
        Http::assertNothingSent();
    }

    public function test_execution_and_buttons_reject_foreign_widget_tokens_even_in_explicit_input(): void
    {
        $other = $this->account('tilda', 'tilda-client');
        $own = $this->account('workflows', 'workflow-client-id');
        $executor = app(WorkflowAmoCrmActionExecutor::class);
        $resolve = new \ReflectionMethod($executor, 'resolveAccount');
        $context = (new WorkflowContext(['account' => ['id' => $other->id]]))->setTriggeredBy(1);
        $this->assertNull($resolve->invoke($executor, $context));
        $context = (new WorkflowContext(['account' => ['id' => $own->id]]))->setTriggeredBy(1);
        $this->assertSame($own->id, $resolve->invoke($executor, $context)->id);
        $context->setTriggeredBy(2);
        $this->assertNull($resolve->invoke($executor, $context));

        $controller = new WorkflowManualAmoCrmController;
        $resolveButton = new \ReflectionMethod($controller, 'resolveAccount');
        $request = Request::create('/', 'GET', ['subdomain' => 'test']);
        $this->assertSame($own->id, $resolveButton->invoke($controller, $request)->id);
        $own->forceFill(['active' => false])->saveQuietly();
        $this->assertNull($resolveButton->invoke($controller, $request));
        Http::assertNothingSent();
    }

    public function test_foreign_connection_cannot_subscribe_or_receive_workflow_webhooks(): void
    {
        $other = $this->account('tilda', 'tilda-client');
        $service = app(WorkflowAmoCrmWebhookService::class);
        $this->assertFalse($service->synchronizeAccount($other)['ok']);
        $response = (new WorkflowWebhookController)->amoCrm(
            Request::create('/', 'POST'), $other, $service->signature($other), $service,
        );
        $this->assertSame(403, $response->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_missing_widget_oauth_values_never_use_platform_or_stored_keys(): void
    {
        $own = $this->account('workflows', 'workflow-client-id');
        config([
            'services.amocrm.widgets.workflows.client_id' => '',
            'services.amocrm.widgets.workflows.client_secret' => '',
            'services.amocrm.widgets.workflows.redirect_uri' => '',
        ]);
        $page = new class {
            use SyncAmoCRMPage;
            public function clientId(Account $account): string { return $this->resolveOauthClientId('workflows', $account); }
        };
        $this->assertSame('', $page->clientId($own));
        $controller = new AuthController;
        $oauth = (new \ReflectionMethod($controller, 'resolveOauthConfigForWidget'))
            ->invoke($controller, 'workflows', User::findOrFail(1), $own);
        $this->assertSame(['client_secret' => '', 'redirect_uri' => ''], $oauth);
        $this->assertNull(User::findOrFail(1)->resolveAmoAccountForWidget('workflows'));
        $this->assertFalse(WorkflowConnectionAccess::hasActiveConnection(1));
    }

    public function test_install_requires_every_own_oauth_value_even_if_platform_keys_exist(): void
    {
        $service = app(AmoCrmWidgetInstallationService::class);
        $method = new \ReflectionMethod($service, 'oauthConfig');
        $valid = $method->invoke($service, 'workflows');
        $this->assertSame('workflow-client-id', $valid['client_id']);
        foreach (array_keys($valid) as $missing) {
            config(['services.amocrm.widgets.workflows' => $valid]);
            config(['services.amocrm.widgets.workflows.'.$missing => '']);
            try {
                $method->invoke($service, 'workflows');
                $this->fail('Missing workflow OAuth '.$missing.' must not use platform credentials.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString($missing.' is not configured', $error->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    private function account(string $widget, string $clientId, array $extra = []): Account
    {
        $id = DB::table('accounts')->insertGetId(array_merge([
            'user_id' => 1, 'widget' => $widget, 'client_id' => $clientId,
            'subdomain' => 'test', 'zone' => 'ru', 'active' => true,
            'access_token' => 'test-access', 'refresh_token' => 'test-refresh',
        ], $extra));

        return Account::findOrFail($id);
    }
}
