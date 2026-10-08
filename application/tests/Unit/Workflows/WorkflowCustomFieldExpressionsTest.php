<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Context\WorkflowContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class WorkflowCustomFieldExpressionsTest extends TestCase
{
    private function contact(mixed $value = 12345, int $id = 25458993): array
    {
        return ['id' => $id, 'custom_fields_values' => [
            ['field_id' => 10, 'values' => [['value' => 'Not the requested field']]],
            ['field_id' => 94781, 'values' => [['value' => $value]]],
        ]];
    }

    private function readOutput(mixed $data): array
    {
        return ['data' => $data, 'items' => isset($data['id']) ? [$data] : ($data['_embedded']['contacts'] ?? []), 'has_more' => false];
    }

    private function context(array $input = []): WorkflowContext
    {
        return (new WorkflowContext($input))->setVariable('_node_names', ['Получить контакты' => ['contacts'], 'Событие' => ['trigger']]);
    }

    private function node(string $id, string $type = 'amocrm_read', array $config = []): array
    {
        return ['id' => $id, 'type' => $type, 'config' => $config + ($type === 'amocrm_read' ? ['operation' => 'contacts.list'] : [])];
    }

    private function definition(array $actions, array $edges): array
    {
        return ['trigger' => ['type' => 'manual'], 'actions' => $actions,
            'connections' => array_map(fn ($edge) => ['sourceId' => $edge[0], 'targetId' => $edge[1], 'sourcePort' => $edge[2] ?? 'output'], $edges)];
    }

    private function singleSource(): array
    {
        return $this->definition([$this->node('contacts'), $this->node('next', 'control-condition')], [
            ['trigger', 'action:contacts'], ['action:contacts', 'action:next'],
        ]);
    }

    public function test_named_field_uses_id_and_preserves_types_without_changing_stored_output(): void
    {
        foreach ([12345, 0, false, '', null] as $value) {
            $output = $this->readOutput($this->contact($value));
            $context = $this->context()->setStepOutput('contacts', $output);
            $before = $context->toArray();
            // Existing custom-field semantics return [] for an empty value list.
            $expected = $value === '' || $value === null ? [] : $value;
            $this->assertSame($expected, $context->resolve('{{ $("Получить контакты").cf(94781) }}'));
            $this->assertSame($expected, $context->resolve("{{ $('Получить контакты').cf( 94781 ) }}"));
            $this->assertSame($before, $context->toArray());
        }
    }

    public function test_named_fields_support_single_item_collections_and_explicit_index(): void
    {
        $context = $this->context()->setStepOutput('contacts', $this->readOutput(['_embedded' => ['contacts' => [$this->contact()]]]));
        $this->assertSame(12345, $context->resolve('{{ $("Получить контакты").cf(94781) }}'));
        $context->setStepOutput('contacts', $this->readOutput(['_embedded' => ['contacts' => [$this->contact(), $this->contact(67890, 2)]]]));
        $this->assertSame(67890, $context->resolve('{{ $("Получить контакты")[1].cf(94781) }}'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('несколько записей');
        $context->resolve('{{ $("Получить контакты").cf(94781) }}');
    }

    public function test_named_source_handles_quoted_name_and_missing_field_or_node(): void
    {
        $name = 'Контакт "Клиент": результат';
        $context = $this->context()->setStepOutput('contacts', $this->readOutput($this->contact()))
            ->setVariable('_node_names', [$name => ['contacts']]);
        $this->assertSame(12345, $context->resolve('{{ $(' . json_encode($name, JSON_UNESCAPED_UNICODE) . ').cf(94781) }}'));
        $this->assertNull($context->resolve('{{ $("Нет ноды").cf(94781) }}'));
        $this->assertNull($context->resolve('{{ $("contacts").cf(1) }}'));
    }

    public function test_automatic_field_prefers_connected_contact_over_trigger_and_other_branch(): void
    {
        $context = $this->context(['contact' => $this->contact(1)])
            ->setStepOutput('contacts', $this->readOutput($this->contact()))
            ->setStepOutput('other', ['entity_type' => 'contact', 'contact' => $this->contact(999, 2)]);
        $definition = $this->singleSource();
        $definition['actions'][] = $this->node('other');
        $definition['connections'][] = ['sourceId' => 'trigger', 'targetId' => 'action:other', 'sourcePort' => 'output'];
        $context->scopeToNode($definition, 'action:next');
        $this->assertSame('12345', $context->resolve('{{contact.cf(94781)}}'));
        $this->assertSame('12345', $context->resolve('{{contact.cf(94781):default(42)}}'));
        $this->assertSame(999, $context->resolve('{{ $("other").cf(94781) }}'));
    }

    public function test_automatic_field_passes_through_non_entity_nodes_and_active_condition(): void
    {
        $definition = $this->definition([$this->node('contacts'), $this->node('if', 'control-condition'), $this->node('next', 'control-condition')], [
            ['trigger', 'action:contacts'], ['action:contacts', 'action:if'], ['action:if', 'action:next', 'no'],
        ]);
        $context = $this->context()->setStepOutput('contacts', $this->readOutput($this->contact()))
            ->setStepOutput('if', ['passed' => false, 'branch' => 'false', 'condition_results' => []]);
        $context->scopeToNode($definition, 'action:next');
        $this->assertSame('12345', $context->resolve('{{contact.cf(94781)}}'));
        $context->setStepOutput('if', ['passed' => true]);
        $context->scopeToNode($definition, 'action:next');
        $this->assertNull($context->get('contact.cf(94781)'));
    }

    public function test_nearest_contact_missing_field_empty_or_not_run_does_not_reuse_old_contact(): void
    {
        foreach ([null, $this->readOutput([]), $this->readOutput(['id' => 2, 'custom_fields_values' => null]), $this->readOutput(['id' => 2, 'custom_fields_values' => []])] as $output) {
            $context = $this->context(['contact' => $this->contact(999)]);
            if ($output !== null) $context->setStepOutput('contacts', $output);
            $context->scopeToNode($this->singleSource(), 'action:next');
            $this->assertNull($context->get('contact.cf(94781)'));
        }
    }

    public function test_automatic_multiple_contacts_require_explicit_selection(): void
    {
        $context = $this->context()->setStepOutput('contacts', $this->readOutput(['_embedded' => ['contacts' => [$this->contact(), $this->contact(5, 2)]]]));
        $context->scopeToNode($this->singleSource(), 'action:next');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('несколько записей');
        $context->resolve('{{contact.cf(94781)}}');
    }

    public function test_merging_contact_sources_is_ambiguous_but_named_source_works(): void
    {
        $definition = $this->definition([$this->node('contacts'), $this->node('other'), $this->node('next', 'control-condition')], [
            ['trigger', 'action:contacts'], ['trigger', 'action:other'], ['action:contacts', 'action:next'], ['action:other', 'action:next'],
        ]);
        $context = $this->context()->setStepOutput('contacts', $this->readOutput($this->contact()))->setStepOutput('other', $this->readOutput($this->contact(9, 2)));
        $context->scopeToNode($definition, 'action:next');
        $this->assertSame(12345, $context->resolve('{{ $("Получить контакты").cf(94781) }}'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('несколько источников');
        $context->resolve('{{contact.cf(94781)}}');
    }

    public function test_disabled_contact_does_not_override_trigger_and_scope_can_be_cleared(): void
    {
        $definition = $this->singleSource();
        $definition['actions'][0]['disabled'] = true;
        $context = $this->context(['contact' => $this->contact(77)])->setStepOutput('contacts', ['entity_type' => 'contact', 'contact' => $this->contact()]);
        $context->scopeToNode($definition, 'action:next');
        $this->assertSame('77', $context->resolve('{{contact.cf(94781)}}'));
        $context->clearNodeScope();
        $this->assertSame('12345', $context->resolve('{{contact.cf(94781)}}'));
    }

    public function test_triggers_and_other_entity_aliases_keep_working(): void
    {
        foreach (['lead', 'contact', 'company', 'customer'] as $entity) {
            $context = $this->context([$entity => $this->contact(55), 'entity' => $entity, 'item' => $this->contact(55)]);
            $this->assertSame('55', $context->resolve('{{'.$entity.'.cf(94781)}}'));
            $this->assertSame(55, $context->resolve('{{ $("Событие").cf(94781) }}'));
        }
        $context = $this->context(['entity' => 'contact', 'item' => $this->contact(66)]);
        $this->assertSame('66', $context->resolve('{{contact.cf(94781)}}'));
    }

    public function test_contact_alias_is_not_satisfied_by_a_lead_with_the_same_field_id(): void
    {
        $definition = $this->singleSource();
        $definition['actions'][0]['config']['operation'] = 'leads.list';
        $context = $this->context(['contact' => $this->contact(77)])->setStepOutput('contacts', $this->readOutput($this->contact(999)));
        $context->scopeToNode($definition, 'action:next');
        $this->assertSame('77', $context->resolve('{{contact.cf(94781)}}'));
    }

    public function test_named_and_automatic_raw_amo_trigger_fields_resolve_without_wrappers(): void
    {
        $context = $this->context(['body' => ['account' => ['id' => 1, 'subdomain' => 'test'], 'contacts' => ['update' => [$this->contact()]]]]);
        $this->assertSame('12345', $context->resolve('{{contact.cf(94781)}}'));
        $this->assertSame(12345, $context->resolve('{{ $("Событие").cf(94781) }}'));
    }

    public function test_a_diamond_reuses_one_source_without_false_ambiguity(): void
    {
        $definition = $this->definition([$this->node('contacts'), $this->node('a', 'workflow_delay'), $this->node('b', 'workflow_delay'), $this->node('next', 'control-condition')], [
            ['trigger', 'action:contacts'], ['action:contacts', 'action:a'], ['action:contacts', 'action:b'], ['action:a', 'action:next'], ['action:b', 'action:next'],
        ]);
        $context = $this->context()->setStepOutput('contacts', $this->readOutput($this->contact()));
        $context->scopeToNode($definition, 'action:next');
        $this->assertSame('12345', $context->resolve('{{contact.cf(94781)}}'));
    }

    public function test_scope_uses_only_selected_start_and_clears_between_nodes(): void
    {
        $definition = $this->definition([$this->node('one', 'control-condition'), $this->node('two', 'control-condition')], [
            ['trigger', 'action:one'], ['trigger:other', 'action:two'],
        ]);
        $definition['additional_triggers'] = [['id' => 'trigger:other', 'type' => 'generic-webhook']];
        $context = $this->context(['_workflow_start_node_id' => 'trigger:other', 'contact' => $this->contact()]);
        $context->scopeToNode($definition, 'action:one');
        $this->assertNull($context->get('contact.cf(94781)'));
        $context->scopeToNode($definition, 'action:two');
        $this->assertSame('12345', $context->resolve('{{contact.cf(94781)}}'));
        $this->assertNull($context->resolve('{{ $("Событие").cf(94781) }}'));
    }

    public function test_multivalue_fields_and_legacy_field_shape_remain_supported(): void
    {
        $contact = ['id' => 42, 'custom_fields_values' => [['id' => 94781, 'values' => [['value' => 'a'], ['value' => 'b']]]]];
        $context = $this->context(['contact' => $contact])->setStepOutput('contacts', $this->readOutput($contact));
        $this->assertSame(['a', 'b'], $context->resolve('{{ $("Получить контакты").cf(94781) }}'));
        $this->assertSame('a, b', $context->resolve('{{contact.cf(94781):join(, )}}'));
    }
}
