<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\ListWorkflows;
use App\Models\Core\Account;
use App\Models\User;
use App\Services\amoCRM\EloquentStorage;
use App\Services\amoCRM\IsolatedOauthClient;
use App\Services\Integrations\AmoCrmDisconnectService;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowAmoCrmDisconnectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WorkflowListDatabase::prepare();
        Schema::table('accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('amo_account_id')->nullable();
            $table->string('zone')->nullable();
            $table->string('code')->nullable();
            $table->string('client_secret')->nullable();
            $table->unsignedInteger('expires_in')->nullable();
            $table->unsignedBigInteger('created_at')->nullable();
        });

        Http::preventStrayRequests();
    }

    public function test_header_shows_check_and_disconnect_only_for_the_current_owners_connection(): void
    {
        $this->createAccount(['user_id' => 2, 'amo_account_id' => 410002, 'subdomain' => 'other']);
        $this->actingAs(User::findOrFail(1));
        $headerAction = new \ReflectionMethod(ListWorkflows::class, 'workflowAmoCrmHeaderAction');

        $action = $headerAction->invoke(new ListWorkflows);
        $this->assertInstanceOf(Action::class, $action);
        $this->assertSame('Подключить amoCRM', $action->getLabel());

        $this->createAccount();
        $menu = $headerAction->invoke(new ListWorkflows);
        $this->assertInstanceOf(ActionGroup::class, $menu);
        $this->assertSame('amoCRM: client', $menu->getLabel());
        $this->assertSame(
            ['Проверить интеграцию', 'Отключить amoCRM'],
            array_values(array_map(fn (Action $action): string => $action->getLabel(), $menu->getActions())),
        );
    }

    public function test_it_removes_hooks_before_clearing_only_the_owners_workflow_credentials(): void
    {
        $id = $this->createAccount();
        $otherWidget = $this->createAccount(['widget' => 'default', 'client_id' => 'platform-client']);
        $otherUser = $this->createAccount(['user_id' => 2, 'amo_account_id' => 410002, 'subdomain' => 'other']);
        $otherWidgetBefore = Account::findOrFail($otherWidget)->getAttributes();
        $otherUserBefore = Account::findOrFail($otherUser)->getAttributes();

        $this->mock(WorkflowAmoCrmWebhookService::class)
            ->shouldReceive('removeAccount')
            ->once()
            ->andReturnUsing(function (Account $account) use ($id): array {
                $this->assertSame($id, (int) $account->getKey());
                $this->assertTrue($account->active);
                $this->assertSame('workflow-refresh', $account->refresh_token);

                $stored = Account::findOrFail($id);
                $this->assertTrue($stored->active);
                $this->assertSame('workflow-access', $stored->access_token);

                return ['ok' => true, 'state' => 'missing', 'removed' => 1];
            });

        $service = app(AmoCrmDisconnectService::class);
        $this->assertTrue($service->disconnectWorkflowsForUser(1));
        $this->assertDisconnected($id);
        $this->assertSame($otherWidgetBefore, Account::findOrFail($otherWidget)->getAttributes());
        $this->assertSame($otherUserBefore, Account::findOrFail($otherUser)->getAttributes());
        $this->assertNull($service->disconnectWorkflowsForUser(1));
    }

    public function test_it_clears_local_credentials_even_when_remote_hook_cleanup_fails(): void
    {
        $id = $this->createAccount();

        $this->mock(WorkflowAmoCrmWebhookService::class)
            ->shouldReceive('removeAccount')
            ->once()
            ->andReturn(['ok' => false, 'state' => 'error']);

        $this->assertFalse(app(AmoCrmDisconnectService::class)->disconnectWorkflowsForUser(1));
        $this->assertDisconnected($id);
    }

    public function test_no_active_connection_does_not_call_amocrm_or_change_accounts(): void
    {
        $inactive = $this->createAccount(['active' => false]);
        $this->createAccount(['user_id' => 2, 'amo_account_id' => 410002, 'subdomain' => 'other']);
        $before = Account::findOrFail($inactive)->getAttributes();

        $this->mock(WorkflowAmoCrmWebhookService::class)->shouldNotReceive('removeAccount');

        $this->assertNull(app(AmoCrmDisconnectService::class)->disconnectWorkflowsForUser(1));
        $this->assertSame($before, Account::findOrFail($inactive)->getAttributes());
    }

    public function test_an_oauth_refresh_started_before_disconnect_cannot_restore_credentials(): void
    {
        $id = $this->createAccount();
        $account = Account::findOrFail($id);
        $options = [
            'domain' => $account->subdomain,
            'client_id' => $account->client_id,
            'client_secret' => $account->client_secret,
            'redirect_uri' => 'https://example.invalid/oauth',
            'zone' => $account->zone,
        ];
        $storage = new EloquentStorage($options, $account);
        $client = IsolatedOauthClient::forAccount($options, $storage);

        $this->mock(WorkflowAmoCrmWebhookService::class)
            ->shouldReceive('removeAccount')
            ->once()
            ->andReturn(['ok' => true, 'state' => 'missing']);
        $this->assertTrue(app(AmoCrmDisconnectService::class)->disconnectWorkflowsForUser(1));
        $this->assertSame('workflow-refresh', $account->refresh_token);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('authorization changed during token refresh');
        try {
            $storage->setOauthData($client, [
                'access_token' => 'late-access',
                'refresh_token' => 'late-refresh',
                'expires_in' => 86400,
                'created_at' => 1_700_000_001,
            ]);
        } finally {
            $this->assertDisconnected($id);
        }
    }

    private function createAccount(array $overrides = []): int
    {
        return DB::table('accounts')->insertGetId(array_replace([
            'user_id' => 1,
            'amo_account_id' => 410001,
            'widget' => 'workflows',
            'client_id' => 'workflow-test-client',
            'active' => true,
            'subdomain' => 'client',
            'zone' => 'ru',
            'code' => 'workflow-code',
            'access_token' => 'workflow-access',
            'refresh_token' => 'workflow-refresh',
            'client_secret' => 'workflow-secret',
            'expires_in' => 86400,
            'created_at' => 1_700_000_000,
        ], $overrides));
    }

    private function assertDisconnected(int $id): void
    {
        $account = Account::findOrFail($id);

        $this->assertSame(1, (int) $account->user_id);
        $this->assertSame(410001, $account->amo_account_id);
        $this->assertSame('workflows', $account->widget);
        $this->assertSame('workflow-test-client', $account->client_id);
        $this->assertSame('client', $account->subdomain);
        $this->assertSame('ru', $account->zone);
        $this->assertFalse($account->active);
        foreach (['code', 'access_token', 'refresh_token', 'client_secret', 'expires_in', 'created_at'] as $field) {
            $this->assertNull($account->{$field}, $field);
        }
    }
}
