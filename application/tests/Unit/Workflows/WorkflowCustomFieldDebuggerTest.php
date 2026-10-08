<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowCustomFieldDebuggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    public function test_both_custom_field_forms_execute_in_a_condition_using_the_fetched_contact(): void
    {
        $input = ['entity' => 'lead', 'item' => ['id' => 123], 'contact' => ['id' => 456, 'custom_fields_values' => [
            ['field_id' => 94781, 'values' => [['value' => 999]]],
        ]]];
        $contact = ['id' => 25458993, 'custom_fields_values' => [['field_id' => 94781, 'values' => [['value' => 12345]]]]];
        $context = (new WorkflowContext($input))->setStepOutput('contacts', ['data' => $contact, 'items' => [$contact], 'has_more' => false]);
        $step = ['id' => 'if', 'type' => 'control-condition', 'config' => ['logic' => 'and', 'conditions' => [
            ['left' => '{{ $("Получить контакты").cf(94781) }}', 'operator' => 'equals', 'right' => 12345],
            ['left' => '{{contact.cf(94781)}}', 'operator' => 'equals', 'right' => 12345],
        ]]];
        $definition = ['trigger' => ['type' => 'manual'], 'actions' => [
            ['id' => 'contacts', 'type' => 'amocrm_read', 'name' => 'Получить контакты', 'config' => ['operation' => 'contacts.list']], $step,
        ], 'connections' => [
            ['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:contacts'],
            ['sourceId' => 'action:contacts', 'sourcePort' => 'output', 'targetId' => 'action:if'],
        ]];
        $session = app(WorkflowDebugger::class)->executeNode($step, $context->toArray(), $input, null, null, false, $definition);
        $this->assertSame('completed', $session['status'], $session['error'] ?? '');
        $this->assertTrue($session['results'][0]['output']['passed']);
        $this->assertSame($input, $session['context']['trigger_data']);
        $this->assertSame($contact, $session['context']['step_outputs']['contacts']['data']);
        Http::assertNothingSent();

        $context->setStepOutput('contacts', ['data' => ['_embedded' => ['contacts' => [$contact, $contact + ['name' => 'Other']]]], 'items' => [$contact, $contact], 'has_more' => false]);
        $step['config']['conditions'] = [$step['config']['conditions'][1]];
        $session = app(WorkflowDebugger::class)->executeNode($step, $context->toArray(), $input, null, null, false, $definition);
        $this->assertSame('failed', $session['status']);
        $this->assertStringContainsString('несколько записей', $session['error']);
        Http::assertNothingSent();
    }
}
