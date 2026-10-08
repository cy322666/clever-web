<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Workflows\Actions\WorkflowHttpRequestAction;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowHttpFormTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Http::preventStrayRequests(); }

    private function action(array $ips = ['93.184.216.34']): WorkflowHttpRequestAction
    {
        return new class($ips) extends WorkflowHttpRequestAction {
            public function __construct(private array $ips) {}
            protected function addresses(string $host): array { return $this->ips; }
        };
    }

    private function config(array $extra = []): array
    {
        return array_replace(['url' => 'https://form.example/submit', 'method' => 'POST', 'body_format' => 'form',
            'form_fields' => [['name' => 'id', 'value' => 17]]], $extra);
    }

    public function test_form_encodes_values_once_and_keeps_repeated_keys_empty_values_and_variables(): void
    {
        $options = [];
        Http::fake(function ($request, $sent) use (&$options) { $options = $sent; return Http::response(['ok' => true]); });
        $context = (new WorkflowContext)->setStepOutput('source', ['value' => 'Иван + &="%20', 'zero' => 0]);
        $result = $this->action()->handle($this->config([
            'headers' => ['cOnTeNt-TyPe' => 'application/json', 'X-Test' => 'keep'],
            'body' => '{{ unfinished stale JSON',
            'form_fields' => [
                ['name' => 'name', 'value' => '{{ $json.value }}'], ['name' => 'zero', 'value' => '{{ $json.zero }}'],
                ['name' => 'blank', 'value' => null], ['name' => 'flag', 'value' => false],
                ['name' => 'tags[]', 'value' => 'a'], ['name' => 'tags[]', 'value' => 'b'],
            ],
        ]), $context);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        Http::assertSent(fn ($request) => $request->body() === 'name=%D0%98%D0%B2%D0%B0%D0%BD+%2B+%26%3D%22%2520&zero=0&blank=&flag=0&tags%5B%5D=a&tags%5B%5D=b'
            && $request->header('Content-Type') === ['application/x-www-form-urlencoded'] && $request->hasHeader('X-Test', 'keep'));
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(['form.example:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
    }

    public function test_all_write_methods_send_forms_but_get_and_head_keep_body_empty(): void
    {
        Http::fake(fn () => Http::response(['ok' => true]));
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'GET', 'HEAD'] as $method) {
            $this->assertTrue($this->action()->handle($this->config(['method' => $method]))['success']);
        }
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(in_array($request->method(), ['GET', 'HEAD']) ? '' : 'id=17', $request->body());
        }
        Http::assertSentCount(6);
    }

    public function test_empty_forms_are_valid_and_json_remains_the_legacy_default(): void
    {
        Http::fake(fn () => Http::response(['ok' => true]));
        $this->assertTrue($this->action()->handle($this->config(['form_fields' => []]))['success']);
        $json = ['url' => 'https://form.example/submit', 'method' => 'POST', 'body' => '{"id":17}', 'form_fields' => [['name' => '', 'value' => '{{broken']]];
        $this->assertTrue($this->action()->handle($json, new WorkflowContext)['success']);
        $requests = Http::recorded();
        $this->assertSame('', $requests[0][0]->body());
        $this->assertTrue($requests[0][0]->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
        $this->assertSame('{"id":17}', $requests[1][0]->body());
        $this->assertTrue($requests[1][0]->hasHeader('Content-Type', 'application/json'));
        $this->assertSame('json', WorkflowHttpRequestAction::workflowDefaultConfig()['body_format']);
    }

    public function test_invalid_form_config_fails_before_http(): void
    {
        Http::fake();
        foreach ([['body_format' => 'invalid'], ['body_format' => []], ['form_fields' => 'invalid'],
            ['form_fields' => [['name' => '', 'value' => 1]]], ['form_fields' => [['name' => 'ids', 'value' => [1, 2]]]],
            ['form_fields' => [['name' => 'value', 'value' => str_repeat('&', 350000)]]],
        ] as $extra) {
            $this->assertFalse($this->action()->handle($this->config($extra))['success']);
            $this->assertNotEmpty(WorkflowDefinitionValidator::configIssues('http_request', $this->config($extra)));
        }
        Http::assertNothingSent();
    }

    public function test_dry_run_and_private_network_guards_still_apply_to_forms(): void
    {
        Http::fake();
        $this->assertTrue($this->action()->handle($this->config(), (new WorkflowContext)->setTriggerSource('test'))['output']['dry_run']);
        $this->assertTrue($this->action()->handle($this->config(), (new WorkflowContext)->setVariable('_dry_run', true))['output']['dry_run']);
        $this->assertTrue($this->action()->handle($this->config(), (new WorkflowContext)->setVariable('_test_mode', true))['output']['dry_run']);
        $this->assertFalse($this->action(['127.0.0.1'])->handle($this->config())['success']);
        Http::assertNothingSent();
    }

    public function test_activation_ignores_inactive_body_but_validates_active_form_and_headers(): void
    {
        $config = $this->config(['body' => '{{ $node["missing"].json.id ']);
        $definition = ['trigger' => ['type' => 'manual'], 'actions' => [['id' => 'http', 'type' => 'http_request', 'config' => $config]]];
        $this->assertSame([], WorkflowDefinitionValidator::issues($definition));
        $this->assertNotEmpty(WorkflowDefinitionValidator::configIssues('http_request', $config + ['headers' => '{bad']));
        $definition['actions'][0]['config']['form_fields'][0]['value'] = '{{ $node["missing"].json.id }}';
        $this->assertStringContainsString('отсутствующую ноду', implode(' ', WorkflowDefinitionValidator::issues($definition)));
        Http::assertNothingSent();
    }

    public function test_editor_saves_and_reopens_form_parameters_without_losing_json_body(): void
    {
        WorkflowCanvasDatabase::prepare();
        $config = ['url' => 'https://form.example/submit', 'method' => 'POST', 'body' => '{"id":17}'];
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [['id' => 'http', 'type' => 'http_request', 'config' => $config]]])
            ->call('openWorkflowActionEditor', 'http')->assertSet('mountedActions.0.data.body_format', 'json');
        $schema = (new \ReflectionMethod($page->instance(), 'getMountedActionSchema'))->invoke($page->instance());
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $this->assertTrue(str_contains($schema->toHtml(), 'Формат тела'));
        $this->assertTrue(str_contains($schema->toHtml(), 'x-www-form-urlencoded'));
        $fields = [['name' => 'id', 'value' => '{{ $json.id }}'], ['name' => 'blank', 'value' => null]];
        $page->set('mountedActions.0.data.body_format', 'form')->set('mountedActions.0.data.form_fields', $fields)
            ->call('callMountedAction')->assertHasNoErrors()->assertSet('workflowActions.0.config.body_format', 'form')
            ->assertSet('workflowActions.0.config.body', '{"id":17}');
        $saved = array_values($page->get('workflowActions')[0]['config']['form_fields']);
        $this->assertSame($fields, $saved);
        $page->call('openWorkflowActionEditor', 'http')->assertSet('mountedActions.0.data.body_format', 'form')
            ->set('mountedActions.0.data.body_format', 'json')->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame($fields, array_values($page->get('workflowActions')[0]['config']['form_fields']));
        $this->assertSame('{"id":17}', $page->get('workflowActions')[0]['config']['body']);
        $page->call('openWorkflowActionEditor', 'http')->set('mountedActions.0.data.form_fields', [['name' => '', 'value' => '']])
            ->call('callMountedAction')->assertHasNoErrors();
        Http::assertNothingSent();
    }
}
