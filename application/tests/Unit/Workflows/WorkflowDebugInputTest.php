<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\Workflows\WorkflowDebugInput;
use App\Services\amoCRM\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowDebugFixture;
use Tests\TestCase;

class WorkflowDebugInputTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); WorkflowCanvasDatabase::prepare(); config(['cache.default' => 'array']); Http::preventStrayRequests(); }

    public function test_status_events_expose_the_same_entity_and_action_aliases_as_real_webhooks(): void
    {
        $input = WorkflowDebugInput::build('lead', 'status_lead', [
            ['key' => 'id', 'type' => 'number', 'value' => '42'],
            ['key' => 'status_id', 'type' => 'number', 'value' => '20'],
            ['key' => '__custom', 'path' => 'custom_fields_values.0.field_id', 'type' => 'number', 'value' => '99'],
        ]);
        $this->assertSame($input['lead'], $input['status']);
        $this->assertSame($input['lead'], $input['payload']['leads']['status'][0]);
        $this->assertSame(99, $input['item']['custom_fields_values'][0]['field_id']);
        $this->assertSame('20', (new \App\Workflows\Context\WorkflowContext($input))->resolve('{{status.status_id}}'));
        $company = WorkflowDebugInput::build('company', 'add_company', [['key' => 'id', 'type' => 'number', 'value' => '7']]);
        $this->assertSame('company', $company['payload']['contacts']['add'][0]['type']);
        $this->assertArrayHasKey('delete_task', WorkflowDebugInput::events('task'));
        Http::assertNothingSent();
    }

    public function test_arbitrary_webhook_fields_and_boolean_false_are_preserved(): void
    {
        $input = WorkflowDebugInput::build('payload', 'webhook', [['key' => 'id', 'type' => 'text', 'value' => 'order-42'], ['key' => '__custom', 'path' => 'paid', 'type' => 'boolean', 'value' => 'false']]);
        $this->assertSame(['id' => 'order-42', 'paid' => false], $input['body']);
        $this->assertSame($input['body'], $input['payload']);
    }

    public function test_incompatible_events_and_overlapping_paths_are_rejected(): void
    {
        foreach ([['contact', 'status_lead', []], ['lead', 'manual', [['key' => '__custom', 'path' => '__proto__.value', 'value' => 'x']]], ['lead', 'manual', [['key' => 'name', 'value' => 'x'], ['key' => 'name', 'value' => 'y']]]] as $case) {
            try { WorkflowDebugInput::build(...$case); $this->fail('Invalid input was accepted.'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('debugInputBuilder', $error->errors()); }
        }
    }

    public function test_loading_an_entity_is_a_single_read_scoped_to_the_owner(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('requestV4')->with('GET', '/api/v4/contacts/42')->willReturn(['id' => 42, 'name' => 'Пример']);
        $loader = new class($client) extends WorkflowDebugInput {
            public function __construct(private Client $testClient) {}
            protected function client(Account $account): Client { return $this->testClient; }
        };
        $account = (new Account)->forceFill(['user_id' => 1, 'active' => true, 'refresh_token' => 'test']);
        $this->assertSame(42, $loader->load($account, 1, 'contact', '42')['id']);
        $this->expectException(ValidationException::class);
        $loader->load($account, 2, 'contact', '42');
    }

    public function test_the_constructor_prepares_data_and_keeps_real_actions_disabled(): void
    {
        $page = Livewire::test(WorkflowDebugFixture::class)->call('openWorkflowDebugger')
            ->assertSet('debugInputMode', 'builder')->assertSee('Конструктор события')
            ->set('debugEntity', 'contact')->set('debugEvent', 'update_contact')->set('debugEntityId', '123')
            ->call('previewDebugInput')->assertHasNoErrors();
        $input = json_decode($page->get('debugInput'), true);
        $this->assertSame(123, $input['contact']['id']);
        $this->assertSame('update_contact', $input['event']);
        $page->call('startWorkflowDebug')->assertHasNoErrors()->assertSet('debugState.real', false);
        $page->set('debugInputMode', 'history')->set('debugRunId', 999)->call('previewDebugInput')->assertHasErrors('debugInputBuilder');
        Http::assertNothingSent();
    }

    public function test_reusing_a_run_is_scoped_to_the_current_workflow_and_user_and_copies_only_input(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Schema::table('workflow_runs', function ($table): void {
            $table->string('status')->default('completed'); $table->timestamp('started_at')->nullable(); $table->json('context_data');
        });
        $this->actingAs(\App\Models\User::findOrFail(1));
        $flow = (new \App\Models\Workflows\Workflow)->forceFill(['id' => 1, 'user_id' => 1, 'name' => 'Тест', 'trigger_type' => 'manual', 'is_active' => false, 'definition' => WorkflowDebugFixture::sampleDefinition()]);
        $flow->saveQuietly();
        foreach ([[1, 1, 1], [2, 2, 1], [3, 1, 2]] as [$id, $owner, $workflow]) {
            \Illuminate\Support\Facades\DB::table('workflow_runs')->insert(['id' => $id, 'user_id' => $owner, 'workflow_id' => $workflow, 'created_at' => now(),
                'context_data' => json_encode(['trigger_data' => ['lead' => ['id' => 77], '_workflow_start_node_id' => 'trigger:removed'], 'variables' => ['_dry_run' => false], 'step_outputs' => ['old' => ['id' => 99]]])]);
        }
        $page = Livewire::test(\Tests\Support\WorkflowSavedDebugFixture::class)->set('debugInputMode', 'history')->set('debugRunId', 1)
            ->call('previewDebugInput')->assertHasNoErrors();
        $this->assertSame(['lead' => ['id' => 77]], json_decode($page->get('debugInput'), true));
        $this->assertSame([1], array_keys($page->instance()->recentDebugRuns()));
        foreach ([2, 3] as $id) $page->set('debugRunId', $id)->call('previewDebugInput')->assertHasErrors('debugInputBuilder');
        $page->set('debugRunId', 1)->call('startWorkflowDebug')->assertHasNoErrors()->assertSet('debugState.real', false)
            ->call('editWorkflowDebugInput')->assertSet('debugSessionId', null)->assertSet('debugState', [])->assertSet('debugInputMode', 'history');
        Http::assertNothingSent();
    }

    public function test_single_condition_check_uses_the_same_owned_trigger_data_as_the_picker(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Schema::table('workflow_runs', fn ($t) => $t->json('context_data'));
        $this->actingAs(\App\Models\User::findOrFail(1));
        $step = ['id' => 'if', 'type' => 'control-condition', 'config' => ['logic' => 'and', 'conditions' => [[
            'left' => '{{ $node["Кнопка"].json.item.pipeline_id }}', 'operator' => 'equals', 'right' => '11003486',
        ]]]];
        $definition = ['trigger' => ['type' => 'amo-button', 'config' => []], 'actions' => [$step]];
        $flow = (new \App\Models\Workflows\Workflow)->forceFill(['id' => 1, 'user_id' => 1, 'name' => 'Тест', 'is_active' => false, 'definition' => $definition]);
        $flow->save();
        foreach ([[1, 1, 11003486], [2, 2, 99]] as [$id, $owner, $pipeline]) {
            \Illuminate\Support\Facades\DB::table('workflow_runs')->insert(['id' => $id, 'user_id' => $owner, 'workflow_id' => 1,
                'context_data' => json_encode(['trigger_data' => ['item' => ['pipeline_id' => $pipeline]], 'step_outputs' => ['old' => ['id' => 99]]])]);
        }
        $page = Livewire::test(\Tests\Support\WorkflowSavedDebugFixture::class)
            ->set('trigger', $definition['trigger'])->set('definition', $definition)->set('workflowActions', [$step])
            ->call('openWorkflowActionEditor', 'if')->call('runEditingWorkflowNode')->assertHasNoErrors()
            ->assertSet('nodeRunResults.if.status', 'completed')->assertSet('nodeRunResults.if.output.passed', true)
            ->assertSet('debugInputSource', 'Данные запуска #1');
        $input = json_decode($page->get('debugInput'), true);
        $this->assertSame(['item' => ['pipeline_id' => 11003486]], $input);
        $context = \Illuminate\Support\Facades\Cache::get('workflow-node-preview:1:'.$page->get('nodePreviewSessionId'));
        $this->assertArrayNotHasKey('old', $context['step_outputs']);
        Http::assertNothingSent();
    }
}
