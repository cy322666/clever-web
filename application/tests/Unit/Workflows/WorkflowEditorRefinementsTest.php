<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\{WorkflowDefinitionValidator, WorkflowExpressionCatalog, WorkflowGraph, WorkflowOutputView};
use App\Workflows\Context\WorkflowContext;
use Livewire\Livewire;
use Tests\Support\{WorkflowCanvasDatabase, WorkflowCanvasFixture};
use Tests\TestCase;

class WorkflowEditorRefinementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    public function test_deleting_an_intermediate_step_preserves_branch_port_and_continuation(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('openAddActionOnConnection', 'action:condition', 'no', 'action:note')
            ->call('selectActionType', 'amocrm_add_note');
        $id = $page->get('workflowActions')[1]['id'];
        $page->call('removeWorkflowAction', $id);
        $edges = $page->get('definition')['connections'];
        $this->assertSame(['action:note'], WorkflowGraph::targets($edges, 'action:condition', 'no'));
        $this->assertSame(['action:task'], WorkflowGraph::targets($edges, 'action:condition', 'yes'));
        $this->assertCount(3, WorkflowGraph::nodes($page->get('workflowActions')));
    }

    public function test_clipboard_copies_only_selected_nodes_and_internal_edges_with_fresh_references(): void
    {
        $actions = [
            ['id'=>'a','name'=>'Источник','type'=>'amocrm_read','config'=>['operation'=>'leads.list']],
            ['id'=>'b','name'=>'Следующий','type'=>'amocrm_add_note','config'=>['text'=>'{{ $("Источник").id }} / {{ $node["a"].json.id }} / {{ $("Внешний").id }}']],
            ['id'=>'c','name'=>'Внешний','type'=>'amocrm_read','config'=>['operation'=>'leads.list']],
        ];
        $edge = fn ($a,$b) => ['sourceId'=>$a,'sourcePort'=>'output','targetId'=>$b];
        $definition = ['trigger'=>['type'=>'manual','config'=>[]],'actions'=>$actions,'connections'=>[$edge('trigger','action:a'),$edge('action:a','action:b'),$edge('action:b','action:c')]];
        $page = Livewire::test(WorkflowCanvasFixture::class)->set('workflowActions',$actions)->set('definition',$definition)
            ->call('copyWorkflowNodes',['action:a','action:b']);
        $this->assertSame($definition,$page->get('definition'), 'Copy must not dirty the graph');
        $page->call('pasteWorkflowNodes');
        $pasted = array_slice($page->get('workflowActions'),3);
        $this->assertCount(2,$pasted);
        $this->assertNotSame('a',$pasted[0]['id']);
        $this->assertSame('{{ $("Источник (копия)").id }} / {{ $node["'.$pasted[0]['id'].'"].json.id }} / {{ $("Внешний").id }}',$pasted[1]['config']['text']);
        $this->assertSame([$edge('action:'.$pasted[0]['id'],'action:'.$pasted[1]['id'])],array_slice($page->get('definition')['connections'],3));
        $page->call('pasteWorkflowNodes');
        $this->assertCount(7,$page->get('workflowActions'));
        WorkflowGraph::ordered($page->get('definition'));
    }

    public function test_selected_condition_does_not_copy_unselected_nested_children(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('copyWorkflowNodes',['action:condition','action:task'])->call('pasteWorkflowNodes');
        $nodes = WorkflowGraph::nodes($page->get('workflowActions'));
        $this->assertCount(5,$nodes);
        $copies = array_slice($page->get('workflowActions'),1);
        $this->assertSame([], $copies[0]['config']['true_actions'] ?? []);
        $this->assertSame(['action:'.$copies[1]['id']],WorkflowGraph::targets($page->get('definition')['connections'],'action:'.$copies[0]['id'],'yes'));
    }

    public function test_short_paths_use_entity_body_and_keep_old_raw_paths_and_false_values(): void
    {
        $body = ['id'=>42,'price'=>0,'_links'=>['self'=>['href'=>'internal']], '_embedded'=>['contacts'=>[['id'=>7]]]];
        $output = ['data'=>$body,'items'=>[$body],'count'=>1,'has_more'=>false];
        $context = (new WorkflowContext)->setStepOutput('a',$output)->setStepOutput('if',['passed'=>false,'branch'=>'false','condition_results'=>[]])
            ->setVariable('_node_names',['Сделка'=>['a'],'Условие'=>['if']]);
        $this->assertSame(0,$context->resolve('{{ $("Сделка").price }}'));
        $this->assertSame(7,$context->resolve("{{ $('Сделка').contacts[0].id }}"));
        $this->assertFalse($context->resolve('{{ $("Условие") }}'));
        $this->assertSame($body,$context->resolve('{{ $node["a"].json.data }}'));
        $this->assertSame($output,$context->getStepOutput('a'));
        $this->assertSame(['id'=>42,'price'=>0,'contacts'=>[['id'=>7]]],WorkflowOutputView::value($output));
    }

    public function test_aggregate_collections_and_empty_results_do_not_turn_into_last_http_page(): void
    {
        $output = ['items'=>[['id'=>1],['id'=>2]],'count'=>2,'has_more'=>false,'amo_exchange'=>[['response'=>['body'=>['_embedded'=>['leads'=>[['id'=>2]]]]]]]];
        $this->assertSame([['id'=>1],['id'=>2]],WorkflowOutputView::value($output));
        $this->assertSame([],WorkflowOutputView::value(['data'=>[],'items'=>[],'count'=>0,'has_more'=>false]));
        $this->assertSame(['data'=>'business value'],WorkflowOutputView::value(['data'=>'business value']));
    }

    public function test_renaming_short_references_and_validation_use_human_names_without_step_ids(): void
    {
        $value = ['a'=>'{{ $("Сделка").id }}', 'b'=>"{{ $('Сделка').price }}", 'javascript_code'=>'$("Сделка")'];
        $result = WorkflowExpressionCatalog::remapReferences($value,['a'=>'Сделка'],['a'=>'Заказ']);
        $this->assertSame('{{ $("Заказ").id }}',$result['a']);
        $this->assertSame('{{ $("Заказ").price }}',$result['b']);
        $this->assertSame($value['javascript_code'],$result['javascript_code']);
        $definition = ['trigger'=>['type'=>'manual','config'=>[]],'actions'=>[['id'=>'step_secret','name'=>'Проверка','type'=>'control-condition','config'=>[]]]];
        $errors = implode(' ',WorkflowDefinitionValidator::issues($definition));
        $this->assertStringContainsString('«Проверка»:',$errors);
        $this->assertStringNotContainsString('step_secret',$errors);
        $definition['actions'][0] = ['id'=>'b','type'=>'amocrm_add_note','config'=>['text'=>'{{ $("Удалённая").id }}']];
        $this->assertStringContainsString('«Удалённая»',implode(' ',WorkflowDefinitionValidator::issues($definition)));
    }
}
