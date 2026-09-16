<?php

namespace Tests\Unit\Workflows;

use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowSubscriptionAccess;
use App\Workflows\Actions\ControlConditionAction;
use App\Workflows\Engine\WorkflowDebugger;
use App\Workflows\Engine\WorkflowExecutor;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowDebuggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        $this->app->instance(WorkflowSubscriptionAccess::class, new class
        {
            public function assertCanExecute(int $userId): void {}
        });
        Http::preventStrayRequests();
    }

    public function test_each_call_executes_one_node_and_only_the_selected_branch(): void
    {
        $spy = new class
        {
            public array $calls = [];

            public function handle(array $config): array
            {
                $this->calls[] = $config;

                return ['success' => true, 'output' => $config];
            }
        };
        $registry = $this->createMock(ActionRegistry::class);
        $registry->method('has')->willReturn(true);
        $registry->method('resolve')->willReturnCallback(fn ($type) => $type === 'control-condition' ? new ControlConditionAction : $spy);
        $runner = new WorkflowDebugger($registry);
        $session = $runner->start(['trigger' => ['type' => 'manual'], 'actions' => [
            $this->step('fetch', ['count' => 1]) + ['name' => 'Запрос сделок'],
            ['id' => 'if', 'type' => 'control-condition', 'config' => [
                'conditions' => [['left' => '{{ $node["Запрос сделок"].json.count }}', 'operator' => 'gt', 'right' => '0']],
                'has_true_branch' => true, 'has_false_branch' => true,
                'true_actions' => [$this->step('yes', ['value' => '{{ $node["fetch"].json.count }}'])],
                'false_actions' => [$this->step('no')],
            ]], $this->step('after'),
        ]], [], null, null);
        $this->assertSame([], $spy->calls);
        $session = $runner->advance($session);
        $this->assertCount(1, $spy->calls);
        $this->assertCount(1, $session['results']);
        $session = $runner->advance($session);
        $this->assertCount(1, $spy->calls);
        $this->assertSame('yes', $session['pending'][0]['step']['id']);
        $session = $runner->advance($session);
        $this->assertSame(1, $spy->calls[1]['value']);
        $session = $runner->advance($session);
        $this->assertSame('completed', $session['status']);
        $this->assertSame(['fetch', 'if', 'yes', 'after'], array_column($session['results'], 'id'));
        $this->assertSame($session, $runner->advance($session));
    }

    public function test_filled_condition_uses_button_input_and_distinguishes_missing_data_from_empty_configuration(): void
    {
        $runner = app(WorkflowDebugger::class);
        $step = ['id' => 'if', 'type' => 'control-condition', 'config' => ['conditions' => [[
            'left' => '{{ $node["Кнопка"].json.item.pipeline_id }}', 'operator' => 'equals', 'right' => '11003486',
        ]]]];
        $definition = ['trigger' => ['type' => 'amo-button'], 'actions' => [$step]];
        $session = $runner->executeNode($step, [], ['item' => ['pipeline_id' => 11003486]], null, null, false, $definition);
        $this->assertSame('completed', $session['status']);
        $this->assertTrue($session['results'][0]['output']['passed']);
        $this->assertSame(11003486, $session['results'][0]['resolved_input']['conditions'][0]['left']);
        $session = $runner->executeNode($step, [], [], null, null, false, $definition);
        $this->assertSame('failed', $session['status']);
        $this->assertStringContainsString('нет данных для переменной', $session['error']);
        $this->assertStringNotContainsString('заполните левое', $session['error']);
        $step['config']['conditions'][0]['left'] = '';
        $session = $runner->executeNode($step, [], [], null, null, false, $definition);
        $this->assertStringContainsString('левое значение', $session['error']);
        Http::assertNothingSent();
    }

    public function test_production_condition_validation_matches_the_debugger(): void
    {
        $executor = new class(app(ActionRegistry::class)) extends \App\Workflows\Engine\WorkflowExecutor {
            public function one(array $step, \Leek\FilamentWorkflows\Context\WorkflowContext $context): array
            { return $this->runActionStep($step, $context); }
        };
        $step = ['id' => 'if', 'type' => 'control-condition', 'config' => ['conditions' => [[
            'left' => '{{ $node["Кнопка"].json.item.pipeline_id }}', 'operator' => 'equals', 'right' => '11003486',
        ]]]];
        $context = (new \App\Workflows\Context\WorkflowContext(['item' => ['pipeline_id' => 11003486]]))
            ->setVariable('_node_names', ['Кнопка' => ['trigger']]);
        $this->assertTrue($executor->one($step, $context)['output']['passed']);
        $context->setTriggerData([]);
        $this->assertStringContainsString('нет данных для переменной', $executor->one($step, $context)['error']);
        $step['config']['conditions'][0]['operator'] = 'is_empty';
        $this->assertTrue($executor->one($step, $context)['output']['passed']);
        Http::assertNothingSent();
    }

    public function test_safe_mode_does_not_send_write_requests_to_amo(): void
    {
        $runner = app(WorkflowDebugger::class);
        $session = $runner->start(['trigger' => ['type' => 'manual'], 'actions' => [[
            'id' => 'note', 'type' => 'amocrm_add_note', 'config' => ['text' => 'Проверка'],
        ]]], [], null, null);
        $session = $runner->advance($session);
        $this->assertSame('simulated', $session['results'][0]['status']);
        $this->assertTrue($session['results'][0]['output']['dry_run']);
        Http::assertNothingSent();
    }

    public function test_real_mode_is_explicit_and_errors_stop_before_the_next_node(): void
    {
        $executor = $this->createMock(WorkflowAmoCrmActionExecutor::class);
        $executor->expects($this->once())->method('execute')->willReturnCallback(function ($type, $config, $context): array {
            $this->assertFalse($context->getVariable('_dry_run'));

            return ['success' => false, 'error' => 'Тестовая ошибка'];
        });
        $this->app->instance(WorkflowAmoCrmActionExecutor::class, $executor);
        $runner = app(WorkflowDebugger::class);
        $session = $runner->start(['trigger' => ['type' => 'manual'], 'actions' => [
            ['id' => 'note', 'type' => 'amocrm_add_note', 'config' => ['text' => 'Проверка']], $this->step('next'),
        ]], [], null, null, true);
        $session = $runner->advance($session);
        $this->assertSame('failed', $session['status']);
        $this->assertSame('Тестовая ошибка', $session['error']);
        $this->assertCount(1, $session['results']);
        $this->assertSame($session, $runner->advance($session));
    }

    public function test_real_mode_is_blocked_without_an_active_period_while_safe_mode_stays_available(): void
    {
        $this->app->forgetInstance(WorkflowSubscriptionAccess::class);
        $runner = app(WorkflowDebugger::class);
        $definition = ['trigger' => ['type' => 'manual'], 'actions' => [$this->step('check')]];

        $this->assertSame('ready', $runner->start($definition, [], null, 1, false)['status']);

        $this->expectException(NonRetryableWorkflowException::class);
        $runner->start($definition, [], null, 1, true);
    }

    public function test_production_executor_cannot_create_a_run_without_an_active_period(): void
    {
        $this->app->forgetInstance(WorkflowSubscriptionAccess::class);
        $workflow = (new Workflow)->forceFill(['user_id' => 1]);

        $this->expectException(NonRetryableWorkflowException::class);
        app(WorkflowExecutor::class)->start($workflow);
    }

    public function test_disabled_condition_bypasses_to_yes_without_evaluation(): void
    {
        $runner = app(WorkflowDebugger::class);
        $session = $runner->start(['trigger' => ['type' => 'manual'], 'actions' => [[
            'id' => 'if', 'type' => 'control-condition', 'disabled' => true,
            'config' => ['has_true_branch' => true, 'true_actions' => [$this->step('yes')]],
        ]]], [], null, null);
        $session = $runner->advance($session);
        $this->assertSame('skipped', $session['results'][0]['status']);
        $this->assertSame('yes', $session['pending'][0]['step']['id']);
    }

    public function test_safe_mode_does_not_execute_standard_write_actions(): void
    {
        $action = new class
        {
            public function handle(array $config): array
            {
                throw new \LogicException('A write action must not run in safe mode.');
            }
        };
        $registry = $this->createMock(ActionRegistry::class);
        $registry->method('has')->willReturn(true);
        $registry->method('resolve')->willReturn($action);
        $runner = new WorkflowDebugger($registry);
        $session = $runner->start(['trigger' => ['type' => 'manual'], 'actions' => [[
            'id' => 'write', 'type' => 'http_request', 'config' => ['url' => 'https://workflow-query.test', 'method' => 'POST'],
        ]]], [], null, null);
        $session = $runner->advance($session);
        $this->assertSame('completed', $session['status']);
        $this->assertSame('simulated', $session['results'][0]['status']);
        Http::assertNothingSent();
    }

    public function test_single_real_node_overrides_old_test_context_and_does_not_reuse_run_identity(): void
    {
        $action = new class extends \App\Workflows\Actions\WorkflowHttpRequestAction {
            protected function addresses(string $host): array { return ['93.184.216.34']; }
        };
        $registry = $this->createMock(ActionRegistry::class);
        $registry->method('has')->willReturn(true);
        $registry->method('resolve')->willReturn($action);
        $runner = new WorkflowDebugger($registry);
        Http::fake(['https://node-run.test/*'=>Http::response(['created'=>true], 201)]);
        $step = ['id'=>'write', 'type'=>'http_request', 'config'=>[
            'url'=>'https://node-run.test/items', 'method'=>'POST', 'body'=>'{"name":"{{ $json.name }}"}',
        ]];
        $context = (new \App\Workflows\Context\WorkflowContext(['name'=>'Из истории']))
            ->setWorkflowId(44)->setWorkflowRunId(55)->setTriggerSource('test')
            ->setVariable('_dry_run', true)->setVariable('_test_mode', true);
        $session = $runner->executeNode($step, $context->toArray(), [], null, 1, true);
        $this->assertSame('completed', $session['status']);
        $this->assertTrue($session['results'][0]['output']['body']['created']);
        $this->assertNull($session['context']['workflow_run_id']);
        $this->assertNull($session['context']['workflow_id']);
        $this->assertSame(1, $session['context']['triggered_by']);
        $this->assertSame('debug', $session['context']['trigger_source']);
        $this->assertFalse($session['context']['variables']['_dry_run']);
        $this->assertFalse($session['context']['variables']['_test_mode']);
        $this->assertTrue($session['context']['variables']['_capture_amo_exchange']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['name'] === 'Из истории');
    }

    private function step(string $id, array $config = []): array
    {
        return ['id' => $id, 'type' => 'test-echo', 'config' => $config];
    }
}
