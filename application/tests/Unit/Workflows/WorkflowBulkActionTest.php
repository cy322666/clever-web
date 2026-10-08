<?php

namespace Tests\Unit\Workflows;

use App\Http\Controllers\Api\WorkflowManualAmoCrmController;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Workflows\WorkflowManualAmoCrmRunService;
use App\Services\Workflows\WorkflowTransfer;
use App\Workflows\Triggers\AmoCrmBulkTrigger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowBulkActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Http::preventStrayRequests();
        Bus::fake();
        DB::table('accounts')->insert(['id' => 1, 'user_id' => 1, 'widget' => 'workflows', 'subdomain' => 'bulk-test', 'active' => true]);
    }

    private function seedFlow(int $id, string $type = 'amo-bulk', array $starts = [], int $owner = 1, bool $active = true): void
    {
        DB::table('workflows')->insert([
            'id' => $id, 'user_id' => $owner, 'name' => 'Поток '.$id, 'trigger_type' => 'manual', 'is_active' => $active,
            'definition' => json_encode(['trigger' => ['type' => $type, 'config' => []], 'additional_triggers' => $starts, 'actions' => []]),
        ]);
    }

    private function access(bool $allowed = true): WidgetSubscriptionAccessService
    {
        $access = $this->createMock(WidgetSubscriptionAccessService::class);
        $access->method('canUse')->willReturn($allowed);
        return $access;
    }

    private function request(array $data = []): Request
    {
        return Request::create('/test', 'POST', array_replace([
            'subdomain' => 'bulk-test', 'source' => 'amo-bulk', 'workflow_id' => 1,
            'entities' => [['type' => 'contact', 'id' => 42]],
        ], $data));
    }

    public function test_selector_only_lists_active_owned_bulk_starts(): void
    {
        $this->seedFlow(1);
        $this->seedFlow(2, 'manual', [['id' => 'trigger:bulk', 'type' => 'amo-bulk', 'config' => []]]);
        $this->seedFlow(3, 'amo-button');
        $this->seedFlow(4, 'manual');
        $this->seedFlow(5, owner: 2);
        $this->seedFlow(6, active: false);
        $response = (new WorkflowManualAmoCrmController)->index($this->request(), $this->access());
        $this->assertSame([1, 2], array_column($response->getData(true)['workflows'], 'id'));
    }

    public function test_dispatches_each_typed_entity_once_including_equal_ids_of_different_types(): void
    {
        $this->seedFlow(1);
        $entities = array_map(fn($type) => ['type' => $type, 'id' => 42], ['lead', 'contact', 'company']);
        $calls = [];
        $runs = $this->createMock(WorkflowManualAmoCrmRunService::class);
        $runs->expects($this->never())->method('startForLead');
        $runs->expects($this->never())->method('startButtonForLead');
        $runs->expects($this->exactly(3))->method('startBulkForEntity')->willReturnCallback(function ($workflow, $account, $type, $id) use (&$calls) {
            $this->assertSame(1, $workflow->id);
            $this->assertSame(1, $account->id);
            $calls[] = ['type' => $type, 'id' => $id];
            $id = count($calls);
            return ['run_id' => $id, 'run_ulid' => 'test-'.$id];
        });
        $response = (new WorkflowManualAmoCrmController)->bulkRun($this->request(['entities' => [...$entities, $entities[0]]]), $this->access(), $runs);
        $this->assertSame(202, $response->status());
        $this->assertSame($entities, $calls);
        $this->assertSame([1, 2, 3], $response->getData(true)['run_ids']);
        $this->assertSame(3, $response->getData(true)['entity_count']);
    }

    public function test_bulk_runs_do_not_bypass_owner_activation_start_type_or_subscription(): void
    {
        $this->seedFlow(1, 'manual');
        $this->seedFlow(2, 'amo-button');
        $this->seedFlow(3, owner: 2);
        $this->seedFlow(4, active: false);
        $this->seedFlow(5);
        $runs = $this->createMock(WorkflowManualAmoCrmRunService::class);
        $runs->expects($this->never())->method('startBulkForEntity');
        foreach ([1, 2, 3, 4] as $id) {
            $this->assertSame(404, (new WorkflowManualAmoCrmController)->bulkRun($this->request(['workflow_id' => $id]), $this->access(), $runs)->status());
        }
        $this->assertSame(403, (new WorkflowManualAmoCrmController)->bulkRun($this->request(['workflow_id' => 5]), $this->access(false), $runs)->status());
        $this->assertSame(404, (new WorkflowManualAmoCrmController)->bulkRun($this->request(['subdomain' => 'missing']), $this->access(), $runs)->status());
        Bus::assertNothingDispatched();
    }

    public function test_invalid_or_oversized_batches_are_rejected_before_any_runs(): void
    {
        $this->seedFlow(1);
        $runs = $this->createMock(WorkflowManualAmoCrmRunService::class);
        $runs->expects($this->never())->method('startBulkForEntity');
        foreach ([
            ['entities' => []],
            ['entities' => [['type' => 'task', 'id' => 42]]],
            ['entities' => [['id' => 42]]],
            ['entities' => [['type' => 'lead', 'id' => 0]]],
            ['entities' => [['type' => 'lead', 'id' => 1.5]]],
            ['entities' => [['type' => 'lead', 'id' => 42, 'lead_id' => 999]]],
            ['entities' => array_fill(0, 251, ['type' => 'lead', 'id' => 42])],
            ['lead_ids' => [42]],
            ['source' => 'amo-button'],
            ['source' => null],
        ] as $data) {
            try {
                (new WorkflowManualAmoCrmController)->bulkRun($this->request($data), $this->access(), $runs);
                $this->fail('Invalid batch was accepted: '.json_encode($data));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_context_uses_actual_entity_type_and_only_bulk_start_nodes(): void
    {
        $nextRunId = 20;
        foreach (['lead', 'contact', 'company'] as $entityType) {
            $workflow = (new Workflow)->forceFill(['id' => 1, 'user_id' => 1, 'definition' => [
                'trigger' => ['type' => 'manual', 'config' => []],
                'additional_triggers' => [
                    ['id' => 'trigger:bulk', 'type' => 'amo-bulk', 'config' => []],
                    ['id' => 'trigger:bulk2', 'type' => 'amo-bulk', 'config' => []],
                    ['id' => 'trigger:button', 'type' => 'amo-button', 'config' => []],
                ],
            ]]);
            $contexts = [];
            $runs = [];
            foreach ([1, 2] as $_) {
                $run = $this->getMockBuilder(WorkflowRun::class)->onlyMethods(['update'])->getMock();
                $run->forceFill(['id' => $nextRunId++]);
                $run->expects($this->once())->method('update')->willReturnCallback(function ($data) use (&$contexts) {
                    $contexts[] = $data['context_data']['trigger_data'];
                    return true;
                });
                $runs[] = $run;
            }
            $executor = $this->createMock(WorkflowExecutor::class);
            $executor->expects($this->exactly(2))->method('start')->with($workflow, null, TriggerType::WEBHOOK, 1)->willReturnOnConsecutiveCalls(...$runs);
            $result = (new WorkflowManualAmoCrmRunService($executor))->startBulkForEntity($workflow, Account::findOrFail(1), $entityType, 42);
            $this->assertCount(2, $result['runs']);
            $this->assertSame(['trigger:bulk', 'trigger:bulk2'], array_column($contexts, '_workflow_start_node_id'));
            foreach ($contexts as $context) {
                $this->assertSame($entityType, $context['entity']);
                $this->assertSame('amo-bulk', $context['event']);
                $this->assertSame('amocrm-list-bulk', $context['source']);
                $this->assertFalse($context['is_manual']);
                $this->assertSame(42, $context['item']['id']);
                $this->assertSame(42, $context[$entityType]['id']);
                $this->assertSame(42, $context['payload'][$entityType.'_id']);
                $this->assertSame($entityType, $context['widget']['entity']);
                foreach (array_diff(['lead', 'contact', 'company'], [$entityType]) as $other) {
                    $this->assertArrayNotHasKey($other, $context);
                }
            }
        }
        Bus::assertDispatchedTimes(ExecuteWorkflowJob::class, 6);
    }

    public function test_old_lead_clients_remain_supported_with_explicit_button_and_default_manual_starts(): void
    {
        $this->seedFlow(1, 'manual');
        $this->seedFlow(2, 'amo-button');
        $runs = $this->createMock(WorkflowManualAmoCrmRunService::class);
        $runs->expects($this->once())->method('startForLead')->willReturn(['run_id' => 1, 'run_ulid' => 'manual']);
        $runs->expects($this->once())->method('startButtonForLead')->willReturn(['run_id' => 2, 'run_ulid' => 'button']);
        foreach ([['workflow_id' => 1], ['workflow_id' => 2, 'source' => 'amo-button']] as $data) {
            $request = Request::create('/test', 'POST', $data + ['subdomain' => 'bulk-test', 'lead_ids' => [42, 42]]);
            $this->assertSame(202, (new WorkflowManualAmoCrmController)->bulkRun($request, $this->access(), $runs)->status());
        }
    }

    public function test_bulk_trigger_is_available_in_editor_and_survives_transfer(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->assertSee('Массовое действие')
            ->call('beginTriggerAdd')->call('selectTriggerType', 'amo-bulk')->assertSet('mountedActions', []);
        $this->assertSame('amo-bulk', $page->get('definition')['additional_triggers'][0]['type']);
        $this->assertFalse((new AmoCrmBulkTrigger)->shouldTrigger([], null, ['is_manual' => true]));
        $this->actingAs(\App\Models\User::findOrFail(1));
        $flow = WorkflowTransfer::import(WorkflowTransfer::encode('Массовый', ['trigger' => ['type' => 'amo-bulk', 'config' => []], 'actions' => []]));
        $this->assertSame(TriggerType::WEBHOOK, $flow->trigger_type);
        $this->assertFalse($flow->is_active);
    }
}
