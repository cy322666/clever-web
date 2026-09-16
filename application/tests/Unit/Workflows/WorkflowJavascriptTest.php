<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Actions\WorkflowJavascriptAction;
use App\Workflows\Context\WorkflowContext;
use Tests\TestCase;

class WorkflowJavascriptTest extends TestCase
{
    public function test_javascript_receives_typed_data_and_returns_items_and_logs(): void
    {
        $context = (new WorkflowContext)->setTriggerData(['seed' => 3]);
        $context->setStepOutput('read', ['items' => [['id' => 4], ['id' => 5]]]);
        $result = (new WorkflowJavascriptAction)->handle(['javascript_code' => 'console.log($node["read"].json.items.length); return $input.all().map(item => ({json: {...item.json, doubled: item.json.id * 2, seed: $node["trigger"].json.seed}}));'], $context);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(8, $result['output']['items'][0]['doubled']);
        $this->assertSame(3, $result['output']['items'][0]['seed']);
        $this->assertSame(2, $result['output']['count']);
        $this->assertSame(['2'], $result['output']['_console']);
    }

    public function test_javascript_has_no_host_network_or_module_access(): void
    {
        $result = (new WorkflowJavascriptAction)->handle(['javascript_code' => 'return {process: typeof process, require: typeof require, fetch: typeof fetch, timer: typeof setTimeout};']);
        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(array_fill_keys(['process', 'require', 'fetch', 'timer'], 'undefined'), $result['output']);
    }

    public function test_infinite_loops_and_syntax_errors_are_bounded_errors(): void
    {
        $action = new WorkflowJavascriptAction;
        $start = microtime(true);
        $result = $action->handle(['javascript_code' => 'while (true) {}']);
        $this->assertFalse($result['success']);
        $this->assertLessThan(6, microtime(true) - $start);
        $this->assertStringContainsString('лимит', $result['error']);
        $this->assertFalse($action->handle(['javascript_code' => 'return { broken: ;'])['success']);
        $this->assertFalse($action->handle(['javascript_code' => 'return Promise.resolve(4);'])['success']);
    }

    public function test_code_source_is_not_interpolated_and_json_bodies_keep_types_and_quotes(): void
    {
        $context = (new WorkflowContext)->setTriggerData(['name' => 'Иван "Тест"', 'id' => 7, 'enabled' => false]);
        $resolved = $context->resolve(['javascript_code' => 'return "{{ $json.name }}";', 'body_mode' => 'json', 'json_body' => '{"name":"{{ $json.name }}","id":"{{ $json.id }}","enabled":"{{ $json.enabled }}"}']);
        $this->assertSame('return "{{ $json.name }}";', $resolved['javascript_code']);
        $this->assertSame(['name' => 'Иван "Тест"', 'id' => 7, 'enabled' => false], $resolved['json_body']);
    }
}
