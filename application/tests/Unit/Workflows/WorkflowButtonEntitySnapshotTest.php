<?php
namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use App\Services\Workflows\WorkflowButtonEntitySnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowButtonEntitySnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); WorkflowListDatabase::prepare(); Http::preventStrayRequests();
        DB::table('accounts')->insert(['id'=>11,'user_id'=>2,'widget'=>'workflows','active'=>true,'refresh_token'=>'test']);
    }
    private function input(): array { return ['source'=>'amocrm-button','lead'=>['id'=>42,'name'=>'Stale'],'account'=>['id'=>11],'_workflow_start_node_id'=>'trigger:button','received_at'=>'original']; }
    private function service(Client $client): WorkflowButtonEntitySnapshot
    {
        return new class($client) extends WorkflowButtonEntitySnapshot {
            public function __construct(private Client $api) {}
            protected function client(Account $account): Client { return $this->api; }
        };
    }
    public function test_full_entity_and_related_cards_are_fetched_in_batches_once_per_run(): void
    {
        $client=$this->createMock(Client::class);
        $client->expects($this->exactly(3))->method('requestV4')->willReturnCallback(function($method,$path,$payload,$query) {
            $this->assertSame('GET',$method);
            return match($path) {
                '/api/v4/leads/42'=>['id'=>42,'name'=>'Current','price'=>15000,'pipeline_id'=>3,'status_id'=>4,'custom_fields_values'=>[['field_id'=>99,'values'=>[['value'=>'Test']]]], '_embedded'=>['contacts'=>[['id'=>5],['id'=>6,'is_main'=>true]],'companies'=>[['id'=>7]],'tags'=>[['name'=>'VIP']]]],
                '/api/v4/contacts'=>['_embedded'=>['contacts'=>[['id'=>5,'name'=>'One'],['id'=>6,'name'=>'Main','custom_fields_values'=>[['field_code'=>'PHONE','values'=>[['value'=>'+70000000000']]]]]]]],
                '/api/v4/companies'=>['_embedded'=>['companies'=>[['id'=>7,'name'=>'Company']]]],
            };
        });
        $service=$this->service($client); $data=$service->hydrate($this->input(),2);
        $this->assertSame(15000,$data['lead']['price']); $this->assertSame($data['lead'],$data['item']);
        $this->assertSame(6,$data['contact']['id']); $this->assertCount(2,$data['contacts']);
        $this->assertSame('Company',$data['company']['name']); $this->assertSame(3,$data['payload']['pipeline_id']);
        $this->assertSame('trigger:button',$data['_workflow_start_node_id']);
        $this->assertSame('original',$data['received_at']);
        $this->assertTrue($data['entity_snapshot']['contacts']['complete']);
        $this->assertSame($data,$service->hydrate($data,2));
        Http::assertNothingSent();
    }
    public function test_foreign_account_is_rejected_before_any_network_access(): void
    {
        $client=$this->createMock(Client::class); $client->expects($this->never())->method('requestV4');
        $this->expectException(NonRetryableWorkflowException::class);
        $this->service($client)->hydrate($this->input(),1);
    }
    public function test_missing_entity_fails_before_any_action_can_run(): void
    {
        $client=$this->createMock(Client::class); $client->method('requestV4')->willReturn([]);
        $this->expectException(NonRetryableWorkflowException::class);
        $this->service($client)->hydrate($this->input(),2);
    }
    public function test_other_starts_remain_unchanged(): void
    {
        $client=$this->createMock(Client::class); $client->expects($this->never())->method('requestV4');
        $data=array_replace($this->input(),['source'=>'amocrm-digital-pipeline']);
        $this->assertSame($data,$this->service($client)->hydrate($data,2));
    }
    public function test_worker_persists_hydrated_data_and_exposes_it_to_expressions(): void
    {
        $snapshot=$this->createMock(WorkflowButtonEntitySnapshot::class);
        $snapshot->expects($this->once())->method('hydrate')->with($this->input(),2)
            ->willReturn($this->input()+['contacts'=>[['id'=>9]]]);
        $this->app->instance(WorkflowButtonEntitySnapshot::class,$snapshot);
        $executor=new class(app(\Leek\FilamentWorkflows\Actions\ActionRegistry::class)) extends \App\Workflows\Engine\WorkflowExecutor {
            public function contextFor($run) { return $this->buildContext($run); }
        };
        $run=$this->getMockBuilder(\Leek\FilamentWorkflows\Models\WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->workflow_id=1;
        $run->context_data=(new \App\Workflows\Context\WorkflowContext($this->input()))->toArray();
        $run->setRelation('workflow',(new \App\Models\Workflows\Workflow)->forceFill(['user_id'=>2,'definition'=>['actions'=>[]]]));
        $saved=[];
        $run->method('update')->willReturnCallback(function($fields) use (&$saved) { $saved=$fields; return true; });
        $context=$executor->contextFor($run);
        $this->assertSame(9,$context->getTriggerData()['contacts'][0]['id']);
        $this->assertSame(9,$saved['context_data']['trigger_data']['contacts'][0]['id']);
        $this->assertSame(9,$context->resolve('{{ $node["trigger"].json.contacts[0].id }}'));
    }
}
