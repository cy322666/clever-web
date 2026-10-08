<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowExpressionCatalog;
use App\Workflows\Context\WorkflowContext;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowExpressionsTest extends TestCase
{
    public function test_amo_data_panel_shows_only_response_body_with_original_resolvable_paths(): void
    {
        $actions=[['id'=>'note','type'=>'amocrm_add_note','name'=>'Примечание','config'=>[]],['id'=>'next','type'=>'workflow_delay','config'=>[]]];
        $output=['entity_id'=>42,'amo_exchange'=>[['request'=>['method'=>'POST','url'=>'https://example.test','body'=>['text'=>'test']], 'response'=>['code'=>200,'body'=>['id'=>42]]]]];
        $sources=WorkflowExpressionCatalog::sources($actions,'next',['step_outputs'=>['note'=>$output]]);
        $this->assertSame(['Результат','id'],array_column($sources[1]['fields'],'label'));
        $context=(new WorkflowContext)->setStepOutput('note',$output)->setVariable('_node_names',['Примечание'=>['note']]);
        $this->assertSame(42,$context->resolve($sources[1]['fields'][1]['expression']));
        $this->assertSame(42,$context->resolve('{{ $node["note"].json.entity_id }}'));
    }
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
    }

    public function test_node_references_preserve_types_and_legacy_text_masks(): void
    {
        $context = (new WorkflowContext(['lead' => ['id' => 456]]))->setStepOutput('fetch', ['items' => [['id' => 123]], 'count' => 1, 'empty' => false]);
        $context->setVariable('_node_names', ['Сделки' => ['fetch']]);
        $this->assertSame(123, $context->resolve('{{ $node["fetch"].json.items[0].id }}'));
        $this->assertSame([['id' => 123]], $context->resolve('{{ $("Сделки").item.json.items }}'));
        $this->assertFalse($context->resolve('{{ $json.empty }}'));
        $this->assertSame(456, $context->resolve('{{ $node["trigger"].json.lead.id }}'));
        $this->assertSame('Сделка 123', $context->resolve('Сделка {{ $node["fetch"].json.items[0].id }}'));
        $this->assertSame('456', $context->resolve('{{lead.id}}'));
        $this->assertNull($context->resolve('{{ $node["missing"].json.id }}'));
        $this->assertSame(1, WorkflowContext::fromArray($context->toArray())->resolve('{{ $json.count }}'));
    }

    public function test_picker_excludes_future_nodes_and_the_other_condition_branch(): void
    {
        $actions = [['id' => 'before', 'type' => 'amocrm_query_leads'], ['id' => 'if', 'type' => 'control-condition', 'config' => [
            'true_actions' => [['id' => 'yes', 'type' => 'amocrm_add_note']],
            'false_actions' => [['id' => 'no', 'type' => 'amocrm_add_note']],
        ]], ['id' => 'after', 'type' => 'amocrm_add_note']];
        $sources = WorkflowExpressionCatalog::sources($actions, 'no', []);
        $this->assertSame(['trigger', 'before', 'if'], array_column($sources, 'id'));
        $this->assertFalse($sources[1]['available']);
        $this->assertContains('{{ $node['.json_encode($sources[1]['name'], JSON_UNESCAPED_UNICODE).'].json.items[0].id }}', array_column($sources[1]['fields'], 'expression'));
    }

    public function test_read_query_does_not_invent_lead_fields_before_execution(): void
    {
        $actions = [['id' => 'contacts', 'type' => 'amocrm_read'], ['id' => 'next', 'type' => 'amocrm_add_note']];
        $sources = WorkflowExpressionCatalog::sources($actions, 'next', []);
        $paths = array_column($sources[1]['fields'], 'path');

        $this->assertContains('.data', $paths);
        $this->assertContains('.items[0].id', $paths);
        $this->assertNotContains('.items[0].pipeline_id', $paths);
        $this->assertFalse($sources[1]['available']);
    }

    public function test_input_tree_keeps_short_labels_and_exact_parent_paths(): void
    {
        $fields = WorkflowExpressionCatalog::sources([], null, ['trigger_data' => ['items' => [['Имя.поле' => 'Тест']], 'count' => 1]])[0]['fields'];
        $this->assertSame(['Результат', 'items', '[0]', 'Имя.поле', 'count'], array_column($fields, 'label'));
        $this->assertSame('.items[0]', $fields[3]['parent']);
        $this->assertSame('.items[0]["Имя.поле"]', $fields[3]['key']);
        $this->assertSame('array', $fields[1]['type']);
        $this->assertSame(1, $fields[1]['count']);
        $this->assertSame('Тест', $fields[3]['value']);
    }

    public function test_named_trigger_and_duplicate_actions_resolve_the_exact_nested_output(): void
    {
        $definition = ['trigger'=>['type'=>'generic-webhook','name'=>'Входящий хук'], 'actions'=>[
            ['id'=>'a','type'=>'amocrm_read','name'=>'Мои "сделки": ответ'],
            ['id'=>'b','type'=>'amocrm_read','name'=>'Мои "сделки": ответ'],
            ['id'=>'c','type'=>'amocrm_add_note'],
        ]];
        $context = (new WorkflowContext(['body'=>['leads'=>[['id'=>42]]]]))
            ->setStepOutput('a',['items'=>[['id'=>1]]])->setStepOutput('b',['items'=>[['id'=>2]]])
            ->setVariable('_node_names',WorkflowExpressionCatalog::nodeNames($definition['actions'],$definition));
        $sources = WorkflowExpressionCatalog::sources($definition['actions'],'c',$context->toArray(),null,$definition);
        $this->assertSame(['Входящий хук','Мои "сделки": ответ','Мои "сделки": ответ (2)'],array_column($sources,'name'));
        foreach ([42,1,2] as $index=>$expected) {
            $field = collect($sources[$index]['fields'])->firstWhere('label','id');
            $this->assertSame($expected,$context->resolve($field['expression']));
            $this->assertSame('ID '.$expected,$context->resolve('ID '.$field['expression']));
        }
        $this->assertSame(1,$context->resolve('{{ $node["a"].json.items[0].id }}'));
    }

    public function test_renaming_updates_generated_references_without_touching_ids_or_code(): void
    {
        $before = ['a'=>'Сделки','b'=>'Сделки (2)'];
        $after = ['a'=>'Продажи','b'=>'Сделки'];
        $config = ['text'=>'{{ $node["Сделки"].json.id }} / {{ $node["Сделки (2)"].json.id }} / {{ $node["a"].json.id }}', 'javascript_code'=>'return "{{ $node[\"Сделки\"].json }}";'];
        $result = WorkflowExpressionCatalog::remapReferences($config,$before,$after);
        $this->assertSame('{{ $node["Продажи"].json.id }} / {{ $node["Сделки"].json.id }} / {{ $node["a"].json.id }}',$result['text']);
        $this->assertSame($config['javascript_code'],$result['javascript_code']);
    }

    public function test_additional_start_alias_does_not_read_another_start_payload(): void
    {
        $definition = ['trigger'=>['type'=>'manual'], 'additional_triggers'=>[['id'=>'trigger:hook','type'=>'generic-webhook','config'=>[],'name'=>'Хук']]];
        $context = (new WorkflowContext(['_workflow_start_node_id'=>'trigger:hook','body'=>['id'=>42]]))
            ->setVariable('_node_names',WorkflowExpressionCatalog::nodeNames([],$definition));
        $this->assertSame(42,$context->resolve('{{ $node["Хук"].json.body.id }}'));
        $this->assertNull($context->resolve('{{ $node["trigger:other"].json.body.id }}'));
    }

    public function test_reordering_same_name_nodes_preserves_the_referenced_node(): void
    {
        $actions = [
            ['id'=>'a','type'=>'amocrm_read','name'=>'Сделки','config'=>[]],
            ['id'=>'b','type'=>'amocrm_read','name'=>'Сделки','config'=>[]],
            ['id'=>'c','type'=>'amocrm_add_note','config'=>['text'=>'{{ $node["Сделки"].json.id }}']],
        ];
        $page = \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
            ->set('workflowActions',$actions)
            ->set('definition',['trigger'=>['type'=>'manual','config'=>[]],'actions'=>$actions])
            ->call('reorderWorkflowActions','',0,1);
        $this->assertSame('{{ $node["Сделки (2)"].json.id }}',$page->get('workflowActions')[2]['config']['text']);
        $context = (new WorkflowContext)->setStepOutput('a',['id'=>42])->setStepOutput('b',['id'=>99])
            ->setVariable('_node_names',WorkflowExpressionCatalog::nodeNames($page->get('workflowActions')));
        $this->assertSame(42,$context->resolve($page->get('workflowActions')[2]['config']['text']));
    }

    public function test_webhook_picker_shows_only_body_without_changing_expression_paths_or_runtime_data(): void
    {
        $body = ['leads'=>['status'=>[['id'=>42]]]];
        $input = ['body'=>$body,'payload'=>$body,'headers'=>['host'=>'test'],'query'=>[], 'method'=>'POST','received_at'=>'today'];
        $definition = ['trigger'=>['type'=>'generic-webhook','name'=>'Вебхук']];
        $source = WorkflowExpressionCatalog::sources([],null,['trigger_data'=>$input],null,$definition)[0];
        $this->assertSame('.body',$source['fields'][0]['key']);
        $this->assertSame(['Результат','leads','status','[0]','id'],array_column($source['fields'],'label'));
        $context = (new WorkflowContext($input))->setVariable('_node_names',WorkflowExpressionCatalog::nodeNames([],$definition));
        $this->assertSame(42,$context->resolve($source['fields'][4]['expression']));
        $this->assertSame($input,$context->getTriggerData());
        $this->assertSame($body,$context->resolve($source['fields'][0]['expression']));
    }
}
