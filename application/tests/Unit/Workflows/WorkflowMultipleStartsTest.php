<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowGraph;
use App\Services\Workflows\WorkflowStartNodes;
use App\Workflows\Engine\WorkflowDebugger;
use App\Workflows\Triggers\ScheduleTrigger;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowMultipleStartsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    public function test_new_start_is_independent_and_its_settings_survive_edits(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('beginTriggerAdd')->call('selectTriggerType', 'schedule');
        $definition = $page->get('definition');
        $id = $definition['additional_triggers'][0]['id'];
        $this->assertSame('manual', $definition['trigger']['type']);
        $this->assertSame([], WorkflowGraph::targets($definition['connections'], $id));
        $page->set('mountedActions.0.data.timezone', 'Europe/Moscow')->set('mountedActions.0.data.rules', [
            ['frequency' => 'daily', 'time' => '09:00'], ['frequency' => 'weekly', 'time' => '18:30', 'day_of_week' => '5'],
        ])->call('callMountedAction')->assertHasNoErrors();
        $this->assertCount(2, $page->get('definition')['additional_triggers'][0]['config']['rules']);
        $page->call('connectWorkflowNodes', $id, 'output', 'action:note');
        $this->assertSame(['action:note'], WorkflowGraph::targets($page->get('definition')['connections'], $id));
        $page->call('removeTriggerNode', 'trigger');
        $this->assertSame('schedule', $page->get('trigger')['type']);
        $this->assertSame(['action:note'], WorkflowGraph::targets($page->get('definition')['connections'], 'trigger'));
    }

    public function test_debug_starts_only_the_selected_chain(): void
    {
        $step = fn ($id) => ['id' => $id, 'type' => 'control-condition', 'config' => ['conditions' => [['left' => 1, 'operator' => 'equals', 'right' => 1]]]];
        $definition = ['trigger' => ['type' => 'manual'], 'additional_triggers' => [['id' => 'trigger:timer', 'type' => 'schedule', 'config' => []]], 'actions' => [$step('one'), $step('two')], 'connections' => [
            ['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:one'], ['sourceId' => 'trigger:timer', 'sourcePort' => 'output', 'targetId' => 'action:two'],
        ]];
        $debugger = app(WorkflowDebugger::class);
        $session = $debugger->advance($debugger->start($definition, ['_workflow_start_node_id' => 'trigger:timer'], null, null));
        $this->assertSame(['two'], array_column($session['results'], 'id'));
        $this->assertSame('completed', $session['status']);
        $this->assertSame([], WorkflowGraph::ancestors($definition, 'action:two'));
    }

    public function test_webhook_node_opens_its_own_settings_but_manual_start_does_not(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('beginTriggerAdd')->call('selectTriggerType', 'generic-webhook');
        $id = $page->get('definition')['additional_triggers'][0]['id'];
        $page->call('editTriggerNode', $id)->assertSet('mountedActions.0.name', 'configureTrigger');
        $components = (fn () => $this->getMountedActionSchema(0))->call($page->instance())->getComponents();
        $this->assertCount(2, $components);
        $this->assertInstanceOf(\Filament\Schemas\Components\View::class, $components[1]);
        $page->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('generic-webhook', $page->get('definition')['additional_triggers'][0]['type']);
        $page->call('editTriggerNode', 'trigger')->assertSet('mountedActions', []);
    }

    public function test_webhook_settings_hide_the_secret_url_but_keep_the_copy_action(): void
    {
        $secret = 'fbad0664cfd49ea78e0f70f24cfbd177bed94a3e0d2ad2881061692a40aad7a3';
        $html = view('filament.workflow-builder.generic-webhook-preview', [
            'url' => 'https://app.clevercrm.pro/api/workflows/webhook/15/'.$secret,
            'preview' => null,
        ])->render();

        $this->assertStringContainsString('app.clevercrm.pro\\/', $html);
        $this->assertStringContainsString('\\u2022\\u2022\\u2022\\u2022\\u2022\\u2022', $html);
        $this->assertStringContainsString('Скопировать URL', $html);
        $this->assertStringNotContainsString('<input id="workflow-webhook-url"', $html);
        $this->assertStringNotContainsString($secret, strip_tags($html));
    }

    public function test_schedule_supports_multiple_rules_timezone_and_legacy_config(): void
    {
        $trigger = new ScheduleTrigger;
        $config = ['timezone' => 'Europe/Moscow', 'rules' => [['frequency' => 'daily', 'time' => '09:00'], ['frequency' => 'weekly', 'time' => '18:30', 'day_of_week' => 5]]];
        $this->assertTrue($trigger->shouldTrigger($config, null, ['check_time' => new \DateTimeImmutable('2026-09-11 06:00:00 UTC')]));
        $this->assertTrue($trigger->shouldTrigger($config, null, ['check_time' => new \DateTimeImmutable('2026-09-11 15:30:00 UTC')]));
        $this->assertFalse($trigger->shouldTrigger($config, null, ['check_time' => new \DateTimeImmutable('2026-09-12 15:30:00 UTC')]));
        $this->assertTrue($trigger->validateConfig($config)['valid']);
        $this->assertSame('00 09 * * *', $trigger->buildCronExpression(['frequency' => 'daily', 'time' => '09:00']));
        $this->assertFalse($trigger->validateConfig(['timezone' => 'UTC', 'rules' => [['frequency' => 'custom', 'cron_expression' => 'not cron']]])['valid']);
        $this->assertInstanceOf(\App\Console\Commands\Workflows\ProcessScheduledWorkflows::class, app(\Leek\FilamentWorkflows\Commands\ProcessScheduledWorkflowsCommand::class));
    }

    public function test_all_amo_start_events_are_collected(): void
    {
        $definition = ['trigger' => ['type' => 'manual'], 'additional_triggers' => [['id' => 'trigger:amo', 'type' => 'amocrm-add-lead', 'config' => ['source' => 'amocrm', 'event' => 'add_lead']]]];
        $this->assertSame(['trigger:amo' => 'add_lead'], WorkflowStartNodes::events($definition));
    }

    public function test_manual_and_webhook_events_dispatch_each_matching_start_without_starting_other_types(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        foreach (['manual', 'generic-webhook'] as $type) {
            $workflow = (new \App\Models\Workflows\Workflow)->forceFill(['id' => 1, 'user_id' => 1, 'definition' => [
                'trigger' => ['type' => $type],
                'additional_triggers' => [
                    ['id' => 'trigger:second', 'type' => $type, 'config' => []],
                    ['id' => 'trigger:timer', 'type' => 'schedule', 'config' => []],
                ],
            ]]);
            $contexts = [];
            $runs = [];
            foreach ([10, 11] as $id) {
                $run = $this->getMockBuilder(\Leek\FilamentWorkflows\Models\WorkflowRun::class)->onlyMethods(['update'])->getMock();
                $run->forceFill(['id' => $id]);
                $run->expects($this->once())->method('update')->willReturnCallback(function ($data) use (&$contexts) {
                    $contexts[] = $data['context_data']['trigger_data']['_workflow_start_node_id'];

                    return true;
                });
                $runs[] = $run;
            }
            $executor = $this->createMock(\Leek\FilamentWorkflows\Engine\WorkflowExecutor::class);
            $executor->expects($this->exactly(2))->method('start')->willReturnOnConsecutiveCalls(...$runs);
            $result = $type === 'manual'
                ? (new \App\Services\Workflows\WorkflowManualAmoCrmRunService($executor))->startForLead($workflow, (new \App\Models\Core\Account)->forceFill(['user_id' => 1]), 123)
                : (new \App\Services\Workflows\WorkflowGenericWebhookService($executor))->handleIncomingWebhook($workflow, \Illuminate\Http\Request::create('/test-webhook', 'POST', ['id' => 123]));
            $this->assertSame(['trigger', 'trigger:second'], $contexts);
            $this->assertSame(10, $result['run_id']);
            $this->assertCount(2, $result['runs']);
        }
    }

    public function test_single_node_run_keeps_the_modal_open_and_does_not_run_children(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)->set('debugInput', '{"lead":{"price":15000}}')->call('openWorkflowActionEditor', 'condition')->call('runEditingWorkflowNode')->assertHasNoErrors()->assertSet('mountedActions.0.name', 'configureWorkflowAction');
        $result = $page->get('nodeRunResults');
        $this->assertSame(['condition'], array_keys($result));
        $this->assertSame('completed', $result['condition']['status'], json_encode($result));
        Http::assertNothingSent();
    }
}
