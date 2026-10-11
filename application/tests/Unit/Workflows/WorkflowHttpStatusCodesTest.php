<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\{WorkflowDefinitionValidator, WorkflowHttpStatusCodes, WorkflowLoopRunner};
use App\Workflows\Actions\{ControlConditionAction, WorkflowHttpRequestAction, WorkflowLoopAction};
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Tests\Support\{WorkflowCanvasDatabase, WorkflowCanvasFixture};
use Tests\TestCase;

class WorkflowHttpStatusCodesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function action(string $ip = '93.184.216.34'): WorkflowHttpRequestAction
    {
        return new class($ip) extends WorkflowHttpRequestAction {
            public function __construct(private string $ip) {}
            protected function addresses(string $host): array { return [$this->ip]; }
        };
    }

    private function config(mixed $codes = null): array
    {
        return ['url' => 'https://public.example/users', 'allowed_status_codes' => $codes];
    }

    public function test_only_explicit_codes_and_all_2xx_continue_with_original_output(): void
    {
        $body = ['status' => false, 'result' => null, 'error' => 'User not found'];
        Http::fake(['*' => Http::sequence()->push($body, 404)->push($body, 404)->push($body, 404)
            ->push(['ok' => true], 200)->push('', 204)->push('Conflict', 409)
            ->push('Unauthorized', 401)->push('Forbidden', 403)->push('Failure', 500)]);

        foreach ([[], ['allowed_status_codes' => '']] as $extra) {
            $result = $this->action()->handle($extra + ['url' => 'https://public.example/users']);
            $this->assertFalse($result['success']);
            $this->assertSame('HTTP 404', $result['error']);
        }
        $result = $this->action()->handle($this->config('404, 409'));
        $this->assertSame(['success' => true, 'output' => ['status' => 404, 'body' => $body, 'success' => false]], $result);
        foreach ([200, 204, 409, 401, 403, 500] as $status) {
            $result = $this->action()->handle($this->config('404, 409'));
            $this->assertSame($status < 300 || $status === 409, $result['success']);
            $this->assertSame($status, $result['output']['status']);
            $this->assertSame($status < 300, $result['output']['success']);
        }
        $this->assertSame([404, 409], WorkflowHttpStatusCodes::parse(' 404, 409,404 '));
        $this->assertSame([404], WorkflowHttpStatusCodes::parse(404));
        $this->assertSame([], WorkflowHttpStatusCodes::parse('  '));
        Http::assertSentCount(9);
    }

    public function test_invalid_codes_are_rejected_before_requests_and_on_activation(): void
    {
        Http::fake();
        foreach (['*', '4xx', '400-499', '99', '600', '404,', '404,,409', '404 409', '404.0',
            true, false, [], ['404'], 404.5, '{{ $json.status }}', str_repeat('404,', 100)] as $value) {
            $result = $this->action()->handle($this->config($value));
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('Допустимые коды ответа', $result['error']);
            $this->assertNotEmpty(WorkflowDefinitionValidator::configIssues('http_request', $this->config($value)));
        }
        $this->assertSame([], WorkflowDefinitionValidator::configIssues('http_request', $this->config('404, 409')));
        Http::assertNothingSent();
    }

    public function test_acceptance_does_not_bypass_network_size_or_redirect_guards(): void
    {
        $options = [];
        Http::fake(function ($request, $sent) use (&$options) {
            $options = $sent;
            return match ($request->url()) {
                'https://public.example/large' => Http::response(str_repeat('a', 1048577), 404),
                'https://public.example/timeout' => throw new ConnectionException('timeout'),
                default => Http::response('', 302, ['Location' => 'http://127.0.0.1/private']),
            };
        });
        $config = $this->config('302, 404');
        $this->assertFalse($this->action('127.0.0.1')->handle($config)['success']);
        Http::assertNothingSent();
        $this->assertTrue($this->action()->handle($config, (new WorkflowContext)->setTriggerSource('test'))['output']['dry_run']);
        Http::assertNothingSent();
        foreach (['large', 'timeout'] as $path) {
            $this->assertFalse($this->action()->handle(['url' => 'https://public.example/'.$path] + $config)['success']);
        }
        $result = $this->action()->handle($config);
        $this->assertTrue($result['success']);
        $this->assertSame(302, $result['output']['status']);
        $this->assertFalse($options['allow_redirects']);
        // Laravel records completed fake responses, not the thrown connection error.
        Http::assertSentCount(2);
    }

    public function test_editor_saves_reopens_validates_and_clears_codes(): void
    {
        WorkflowCanvasDatabase::prepare();
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [
            ['id' => 'http', 'type' => 'http_request', 'config' => ['url' => 'https://public.example/users', 'method' => 'GET']],
        ]])->call('openWorkflowActionEditor', 'http');
        $schema = (new \ReflectionMethod($page->instance(), 'getMountedActionSchema'))->invoke($page->instance());
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $this->assertStringContainsString('Допустимые коды ответа', $schema->toHtml());
        $page->set('mountedActions.0.data.allowed_status_codes', '404, 409')->call('callMountedAction')->assertHasNoErrors()
            ->assertSet('workflowActions.0.config.allowed_status_codes', '404, 409')
            ->call('openWorkflowActionEditor', 'http')->assertSet('mountedActions.0.data.allowed_status_codes', '404, 409')
            ->set('mountedActions.0.data.allowed_status_codes', '4xx')->call('callMountedAction')
            ->assertHasErrors(['mountedActions.0.data.allowed_status_codes'])
            ->assertSet('workflowActions.0.config.allowed_status_codes', '404, 409')
            ->set('mountedActions.0.data.allowed_status_codes', '')->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame([], WorkflowHttpStatusCodes::parse($page->get('workflowActions')[0]['config']['allowed_status_codes']));
    }

    public function test_closed_loop_passes_accepted_404_to_condition_and_continues_next_item(): void
    {
        Http::fake(['*' => Http::sequence()->push(['status' => false, 'result' => null], 404)
            ->push(['status' => true, 'result' => ['user_id' => 17]], 200)->push('Failure', 500)]);
        $edge = fn ($from, $to, $port = 'output') => ['sourceId' => $from === 'trigger' ? $from : 'action:'.$from,
            'sourcePort' => $port, 'targetId' => 'action:'.$to];
        $step = fn ($id, $type, $config = []) => compact('id', 'type', 'config');
        $definition = ['trigger' => ['type' => 'manual'], 'actions' => [
            $step('loop', 'workflow_loop', ['items' => [1, 2]]), $step('http', 'http_request', $this->config('404')),
            $step('condition', 'control-condition', ['conditions' => [['left' => '{{ $("HTTP").status }}', 'operator' => 'is_true']]]),
            $step('found', 'probe'), $step('missing', 'probe'), $step('done', 'probe'),
        ], 'connections' => [$edge('trigger', 'loop'), $edge('loop', 'http', 'each'), $edge('http', 'condition'),
            $edge('condition', 'found', 'yes'), $edge('condition', 'missing', 'no'), $edge('found', 'loop'),
            $edge('missing', 'loop'), $edge('loop', 'done', 'done')]];
        $calls = [];
        $context = (new WorkflowContext)->setVariable('_node_names', ['HTTP' => ['http']]);
        $execute = function ($step, $context) use (&$calls) {
            $calls[] = $step['id'];
            return match ($step['type']) {
                'workflow_loop' => (new WorkflowLoopAction)->handle($step['config'], $context),
                'http_request' => $this->action()->handle($step['config'], $context),
                'control-condition' => (new ControlConditionAction)->handle($context->resolve($step['config']), $context),
                default => ['success' => true, 'output' => []],
            };
        };
        (new WorkflowLoopRunner($definition))->run($context, $execute);
        $this->assertSame(['loop', 'http', 'condition', 'missing', 'http', 'condition', 'found', 'done'], $calls);
        Http::assertSentCount(2);

        $calls = [];
        $this->expectExceptionMessage('HTTP 500');
        try { (new WorkflowLoopRunner($definition))->run($context, $execute); }
        finally {
            $this->assertSame(['loop', 'http'], $calls);
            Http::assertSentCount(3);
        }
    }

    public function test_editor_run_marks_accepted_404_completed_and_keeps_response(): void
    {
        WorkflowCanvasDatabase::prepare();
        $body = ['status' => false, 'error' => 'User not found'];
        Http::fake(['*' => Http::response($body, 404)]);
        $registry = $this->createMock(ActionRegistry::class);
        $registry->method('has')->willReturn(true);
        $registry->method('resolve')->willReturn($this->action());
        $result = (new WorkflowDebugger($registry))->executeNode([
            'id' => 'http', 'type' => 'http_request', 'config' => $this->config('404'),
        ], [], [], null, null, true);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(404, $result['results'][0]['output']['status']);
        $this->assertSame($body, $result['results'][0]['output']['body']);
        Http::assertSentCount(1);
    }
}
