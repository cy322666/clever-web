<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowExpressionCatalog;
use App\Services\Workflows\WorkflowOutputView;
use App\Services\Workflows\WorkflowTriggerReferenceCatalog;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;
use Tests\TestCase;

class WorkflowTriggerReferenceCatalogTest extends TestCase
{
    public function test_every_amo_event_has_a_business_field_reference_without_a_recorded_run(): void
    {
        foreach (AmoCrmWebhookTriggerCatalog::eventCodes() as $event) {
            $type = 'amocrm-'.str_replace('_', '-', $event);
            $this->assertNotEmpty(WorkflowTriggerReferenceCatalog::fields($type), $type);
        }

        $definition = ['trigger' => ['type' => 'amocrm-add-message', 'name' => 'amoCRM: входящее сообщение'], 'actions' => []];
        $source = WorkflowExpressionCatalog::sources([], null, ['trigger_data' => ['_workflow_start_node_id' => 'trigger']], null, $definition)[0];
        $fields = collect($source['fields'])->keyBy('path');

        $this->assertFalse($source['available']);
        $this->assertSame('{{ $("amoCRM: входящее сообщение").entity_id }}', $fields['.entity_id']['expression']);
        $this->assertSame('{{ $("amoCRM: входящее сообщение").author.name }}', $fields['.author.name']['expression']);
        $this->assertNull($fields['.entity_id']['value']);
        $this->assertArrayNotHasKey('.payload', $fields);
        $this->assertArrayNotHasKey('.account', $fields);

        $groups = WorkflowTriggerReferenceCatalog::maskGroups($definition);
        $this->assertSame('ID сущности, если передан', $groups['Событие: amoCRM: входящее сообщение'][$fields['.entity_id']['expression']]);
        $html = view('filament.workflow-builder.mask-reference', ['groups' => $groups, 'systemIdGroups' => []])->render();
        $this->assertStringContainsString('entity_id', $html);

        $context = (new WorkflowContext(['_workflow_start_node_id' => 'trigger', 'entity' => 'message',
            'item' => ['id' => 'message-1', 'entity_id' => 91, 'text' => 'Привет'],
        ]))->setVariable('_node_names', WorkflowExpressionCatalog::nodeNames([], $definition));
        $this->assertSame(91, $context->resolve($fields['.entity_id']['expression']));
    }

    public function test_reference_is_specific_to_each_trigger_and_does_not_invent_a_webhook_body(): void
    {
        $definition = ['trigger' => ['type' => 'amocrm-status-lead', 'name' => 'Этап сделки'],
            'additional_triggers' => [['id' => 'trigger:message', 'type' => 'amocrm-add-message', 'name' => 'Сообщение', 'config' => []]],
            'actions' => []];
        $groups = WorkflowTriggerReferenceCatalog::maskGroups($definition);

        $this->assertArrayHasKey('{{ $("Этап сделки").old_status_id }}', $groups['Событие: Этап сделки']);
        $this->assertArrayHasKey('{{ $("Сообщение").entity_id }}', $groups['Событие: Сообщение']);
        $this->assertArrayNotHasKey('{{ $("Сообщение").old_status_id }}', $groups['Событие: Сообщение']);
        $this->assertSame([], WorkflowTriggerReferenceCatalog::fields('generic-webhook'));
        $this->assertSame([], WorkflowTriggerReferenceCatalog::fields('manual'));
    }

    public function test_real_event_keeps_its_reference_fields_when_one_delivery_omits_them(): void
    {
        $definition = ['trigger' => ['type' => 'amocrm-add-message', 'name' => 'Входящее сообщение'], 'actions' => []];
        $item = ['id' => 'message-1', 'text' => 'Привет', 'author' => ['id' => 'person-1']];
        $source = WorkflowExpressionCatalog::sources([], null, ['trigger_data' => [
            '_workflow_start_node_id' => 'trigger', 'source' => 'amocrm', 'entity' => 'message', 'item' => $item,
        ]], null, $definition)[0];
        $fields = collect($source['fields'])->keyBy('key');

        $this->assertTrue($source['available']);
        $this->assertSame($item, $fields['']['value']);
        $this->assertSame('Привет', $fields['.text']['value']);
        $this->assertTrue($fields['.text']['available']);
        $this->assertSame('{{ $("Входящее сообщение").element_id }}', $fields['.element_id']['expression']);
        $this->assertFalse($fields['.element_id']['available']);
        $this->assertNull($fields['.element_id']['value']);
        $this->assertFalse($fields['.author.name']['available']);
        $this->assertSame('person-1', $fields['.author.id']['value']);

        $withoutAuthor = WorkflowExpressionCatalog::sources([], null, ['trigger_data' => [
            'source' => 'amocrm', 'entity' => 'message', 'item' => ['id' => 'message-2', 'author' => null],
        ]], null, $definition)[0];
        $nestedFields = collect($withoutAuthor['fields'])->keyBy('key');
        $this->assertArrayHasKey('.author.name', $nestedFields);
        $this->assertFalse($nestedFields['.author.name']['available']);
    }

    public function test_message_variants_offer_their_own_documented_fields(): void
    {
        $incoming = WorkflowTriggerReferenceCatalog::fields('amocrm-add-message');
        $outgoing = WorkflowTriggerReferenceCatalog::fields('amocrm-add-outgoing-message');

        $this->assertArrayHasKey('element_id', $incoming);
        $this->assertArrayHasKey('message_type', $incoming);
        $this->assertArrayNotHasKey('recipient.id', $incoming);
        $this->assertArrayHasKey('recipient.id', $outgoing);
        $this->assertArrayHasKey('author.user_id', $outgoing);
        $this->assertArrayHasKey('result.text', WorkflowTriggerReferenceCatalog::fields('amocrm-update-task'));
    }

    public function test_outgoing_message_and_template_review_show_only_the_entity_body(): void
    {
        foreach (['outgoing_message', 'chat_template_review'] as $entity) {
            $item = ['id' => 42, 'entity_id' => 91];
            $this->assertSame($item, WorkflowOutputView::trigger([
                'source' => 'amocrm', 'entity' => $entity, 'item' => $item,
                'payload' => ['account' => ['id' => 1], $entity => ['add' => [$item]]],
            ]));
        }
    }
}
