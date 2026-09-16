<?php
namespace Tests\Unit\Workflows;

use App\Workflows\Actions\WorkflowFilterListAction;
use App\Workflows\Context\WorkflowContext;
use Tests\TestCase;

class WorkflowListFilterTest extends TestCase
{
    public function test_it_checks_every_item_and_excludes_the_new_lead(): void
    {
        $context = (new WorkflowContext(['lead'=>['id'=>2]]))->setStepOutput('deals', ['items'=>[
            ['id'=>1,'pipeline_id'=>10,'status_id'=>20], ['id'=>2,'pipeline_id'=>10,'status_id'=>20],
            ['id'=>3,'pipeline_id'=>11,'status_id'=>20], ['id'=>4,'pipeline_id'=>10,'status_id'=>21],
        ]]);
        $result = (new WorkflowFilterListAction)->handle(['items'=>'{{ $json.items }}','rules'=>[
            ['field'=>'pipeline_id','operator'=>'eq','value'=>'10'],
            ['field'=>'status_id','operator'=>'eq','value'=>'20'],
            ['field'=>'id','operator'=>'neq','value'=>'{{lead.id}}'],
        ]], $context);
        $this->assertTrue($result['success']);
        $this->assertSame([1],array_column($result['output']['items'],'id'));
        $this->assertSame(4,$result['output']['input_count']);
        $this->assertTrue($result['output']['has_matches']);
    }
    public function test_empty_lists_or_conditions_are_handled_without_inventing_matches(): void
    {
        $action = new WorkflowFilterListAction;
        $this->assertSame(0,$action->handle(['items'=>[]])['output']['count']);
        $this->assertSame(2,$action->handle(['items'=>[['id'=>1],['id'=>2]]])['output']['count']);
        $this->assertFalse($action->handle(['items'=>['id'=>1]])['success']);
        $this->assertFalse($action->handle(['items'=>[], 'rules'=>[['field'=>'id','operator'=>'typo']]])['success']);
        $this->assertSame(1,$action->handle(['items'=>[['id'=>1],['id'=>2]],'match'=>'any','rules'=>[
            ['field'=>'id','operator'=>'eq','value'=>2],['field'=>'id','operator'=>'eq','value'=>3],
        ]])['output']['count']);
    }
}
