<?php

namespace Tests\Unit\Workflows;

use App\Jobs\Workflows\SynchronizeAmoCrmWebhooks;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use App\Workflows\Triggers\AmoCrmButtonTrigger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowWebhookSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        // Activation requires the owner's connection; do not bypass that production guard.
        (new Account)->forceFill(['user_id'=>1, 'widget'=>'workflows', 'active'=>true, 'subdomain'=>'test', 'refresh_token'=>'test-only'])->saveQuietly();
        config(['cache.default' => 'array', 'workflow-webhooks.public_url' => null, 'app.url' => 'http://localhost:8080']);
    }

    public function test_manual_and_inactive_workflows_do_not_schedule_webhook_installation(): void
    {
        $this->app->instance('env', 'local');
        Bus::fake();
        $manual = $this->workflow('manual');
        $manual->save();
        $manual->update(['name' => 'Изменён вручную']);
        $this->workflow('amocrm-add-lead', active: false)->save();
        $this->workflow('schedule')->save();
        Bus::assertNotDispatched(SynchronizeAmoCrmWebhooks::class);
    }

    public function test_activating_and_disabling_amo_triggers_still_resynchronizes_webhooks(): void
    {
        $this->app->instance('env', 'local');
        $workflow = $this->workflow('amocrm-add-lead', active: false);
        $workflow->saveQuietly();
        Bus::fake();
        $workflow->update(['is_active' => true]);
        Bus::assertDispatched(SynchronizeAmoCrmWebhooks::class);

        (new \Illuminate\Bus\UniqueLock(\Illuminate\Support\Facades\Cache::store('array')))
            ->release(new SynchronizeAmoCrmWebhooks(1));
        Bus::fake();
        $workflow->update(['is_active' => false]);
        Bus::assertDispatched(SynchronizeAmoCrmWebhooks::class);
    }

    public function test_changing_an_amo_trigger_to_manual_still_cleans_up_subscriptions(): void
    {
        $this->app->instance('env', 'local');
        $workflow = $this->workflow('amocrm-add-lead');
        $workflow->saveQuietly();
        Bus::fake();
        $workflow->definition = $this->workflow('manual')->definition;
        $workflow->save();
        Bus::assertDispatched(SynchronizeAmoCrmWebhooks::class);
    }

    public function test_local_manual_workflows_do_not_report_a_webhook_error(): void
    {
        $this->workflow('manual')->saveQuietly();
        $service = app(WorkflowAmoCrmWebhookService::class);
        $result = $service->synchronizeAccount($this->account());
        $this->assertTrue($result['ok']);
        $this->assertSame('not_required', $result['state']);
    }

    public function test_extra_amo_starts_resynchronize_without_changing_the_primary_start(): void
    {
        $this->app->instance('env', 'local');
        $workflow = $this->workflow('manual');
        $workflow->saveQuietly();
        foreach ([true, false] as $add) {
            (new \Illuminate\Bus\UniqueLock(\Illuminate\Support\Facades\Cache::store('array')))
                ->release(new SynchronizeAmoCrmWebhooks(1));
            Bus::fake();
            $definition = $workflow->definition;
            $definition['additional_triggers'] = $add ? [['id' => 'trigger:amo', 'type' => 'amocrm-add-lead', 'config' => ['source' => 'amocrm', 'event' => 'add_lead']]] : [];
            $workflow->definition = $definition;
            $workflow->save();
            Bus::assertDispatched(SynchronizeAmoCrmWebhooks::class);
        }
    }

    public function test_lookup_and_duplicate_guard_find_additional_starts(): void
    {
        $workflow = $this->workflow('schedule');
        $definition = $workflow->definition;
        $definition['additional_triggers'] = [
            ['id' => 'trigger:manual', 'type' => 'manual', 'config' => []],
            ['id' => 'trigger:amo', 'type' => 'amocrm-add-lead', 'config' => ['source' => 'amocrm', 'event' => 'add_lead']],
        ];
        $workflow->definition = $definition;
        $workflow->saveQuietly();
        $this->assertSame($workflow->id, Workflow::query()->withStartType('manual')->first()?->id);
        $this->assertSame($workflow->id, Workflow::activeDuplicateForUniqueTrigger('amocrm-add-lead', userId: 1)?->id);
        $this->assertNull(Workflow::activeDuplicateForUniqueTrigger('amocrm-add-lead', userId: 2));
    }

    public function test_a_local_amo_receiver_is_a_visible_configuration_issue_not_a_retryable_failure(): void
    {
        $this->workflow('amocrm-add-lead')->saveQuietly();
        $service = app(WorkflowAmoCrmWebhookService::class);
        $result = $service->synchronizeAccount($this->account());
        $this->assertFalse($result['ok']);
        $this->assertSame('configuration_required', $result['state']);
        $this->assertStringContainsString('WORKFLOW_PUBLIC_URL', $result['message']);

        $mock = $this->createMock(WorkflowAmoCrmWebhookService::class);
        $mock->method('synchronizeUser')->willReturn($result);
        Log::spy();
        (new SynchronizeAmoCrmWebhooks(1))->handle($mock);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_the_button_uses_manual_execution_instead_of_inheriting_a_schedule(): void
    {
        $workflow = $this->workflow('schedule');
        $workflow->saveQuietly();
        $workflow->trigger_type = TriggerType::SCHEDULE;
        $workflow->definition = $this->workflow(AmoCrmButtonTrigger::type())->definition;
        $workflow->save();
        $this->assertSame(TriggerType::MANUAL, $workflow->fresh()->trigger_type);
        $this->assertTrue($workflow->fresh()->is_active);
        $this->assertFalse((new AmoCrmButtonTrigger)->shouldTrigger([], null, ['is_manual' => true]));
    }

    public function test_actual_webhook_failures_are_still_reported_for_retry(): void
    {
        $service = $this->createMock(WorkflowAmoCrmWebhookService::class);
        $service->method('synchronizeUser')->willReturn(['state' => 'error', 'message' => 'amoCRM недоступна']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('amoCRM недоступна');
        (new SynchronizeAmoCrmWebhooks(1))->handle($service);
    }

    public function test_current_workflow_oauth_secret_and_redirect_are_applied_before_api_calls(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('redirect_uri')->nullable();
        });
        config([
            'services.amocrm.widgets.workflows.client_id' => 'workflow-client-id',
            'services.amocrm.widgets.workflows.client_secret' => 'current-secret',
            'services.amocrm.widgets.workflows.redirect_uri' => 'https://app.example.test/api/amocrm/install/flow',
        ]);
        $account = (new Account)->forceFill([
            'user_id' => 1,
            'widget' => 'workflows',
            'active' => true,
            'subdomain' => 'example',
            'refresh_token' => 'test-only',
            'client_id' => 'workflow-client-id',
            'client_secret' => 'stale-secret',
            'redirect_uri' => 'https://app.example.test/api/amocrm/redirect',
        ]);
        $account->saveQuietly();

        app(WorkflowAmoCrmWebhookService::class)->statusForUser(1);

        $account->refresh();
        $this->assertSame('current-secret', $account->client_secret);
        $this->assertSame('https://app.example.test/api/amocrm/install/flow', $account->redirect_uri);
    }

    private function workflow(string $type, bool $active = true): Workflow
    {
        return (new Workflow)->forceFill([
            'user_id' => 1, 'name' => 'Проверка', 'is_active' => $active, 'trigger_type' => 'manual',
            'definition' => ['trigger' => ['type' => $type, 'config' => str_starts_with($type, 'amocrm-') ? ['source' => 'amocrm', 'event' => 'add_lead'] : []],
                'actions' => [['id' => 'note', 'type' => 'amocrm_add_note', 'config' => ['text' => 'Тест']]]],
        ]);
    }

    private function account(): Account
    {
        return (new Account)->forceFill(['id' => 1, 'user_id' => 1, 'active' => true, 'subdomain' => 'test', 'refresh_token' => 'test-only']);
    }
}
