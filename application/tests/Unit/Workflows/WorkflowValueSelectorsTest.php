<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Context\WorkflowContext;
use Tests\TestCase;

class WorkflowValueSelectorsTest extends TestCase
{
    public function test_first_and_last_support_short_raw_and_input_paths_and_keep_indexes(): void
    {
        $context = (new WorkflowContext(['rows' => [['id' => 1], ['id' => 2]]]))
            ->setStepOutput('contacts', ['items' => [['id' => 11], ['id' => 22]], 'has_more' => false])
            ->setVariable('_node_names', ['Контакты' => ['contacts']]);
        foreach (['first', 'first()'] as $selector) {
            $this->assertSame(11, $context->resolve('{{ $("Контакты").'.$selector.'.id }}'));
            $this->assertSame(11, $context->resolve('{{ $node["contacts"].json.items.'.$selector.'.id }}'));
            $this->assertSame(11, $context->resolve('{{ $json.items.'.$selector.'.id }}'));
            $this->assertSame(1, $context->resolve('{{ $("trigger").rows.'.$selector.'.id }}'));
        }
        foreach (['last', 'last()'] as $selector) $this->assertSame(22, $context->resolve('{{ $("Контакты").'.$selector.'.id }}'));
        $this->assertSame(11, $context->resolve('{{ $("Контакты")[0].id }}'));
        $this->assertSame('ID 22', $context->resolve('ID {{ $("Контакты").last().id }}'));
        $context->setStepOutput('contacts', []);
        $this->assertNull($context->resolve('{{ $("Контакты").first().id }}'));
        $this->assertNull($context->resolve('{{ $("Контакты").last().id }}'));
    }

    public function test_unwrapped_entity_nested_lists_and_real_first_last_fields(): void
    {
        $context = (new WorkflowContext)->setStepOutput('contact', ['id' => 5, 'groups' => [['members' => [['id' => 7], ['id' => 8]]]]]);
        $this->assertSame(5, $context->resolve('{{ $("contact").first().id }}'));
        $this->assertSame(5, $context->resolve('{{ $("contact").last().id }}'));
        $this->assertSame(8, $context->resolve('{{ $("contact").groups.first().members.last().id }}'));
        $context->setStepOutput('contact', ['first' => false, 'last' => 0]);
        $this->assertFalse($context->resolve('{{ $("contact").first }}'));
        $this->assertSame(0, $context->resolve('{{ $("contact").last }}'));
        $this->assertNull($context->resolve('{{ $("contact").pop() }}'));
    }
}
