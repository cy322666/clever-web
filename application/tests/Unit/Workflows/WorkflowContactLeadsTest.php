<?php
namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkflowContactLeadsTest extends TestCase
{
    private function fetch(array $config): array
    {
        $executor = app(WorkflowAmoCrmActionExecutor::class);
        return (new \ReflectionMethod($executor,'contactLeads'))->invoke($executor,
            (new Account)->forceFill(['endpoint'=>'https://contact-leads.test','access_token'=>'test-only']),$config);
    }
    public function test_main_contact_all_batches_and_excluded_new_lead(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(),PHP_URL_PATH);
            parse_str(parse_url($request->url(),PHP_URL_QUERY) ?: '',$query);
            if ($path === '/api/v4/leads/99') return Http::response(['_embedded'=>['contacts'=>[['id'=>1],['id'=>2,'is_main'=>true]]]]);
            if ($path === '/api/v4/contacts/2') return Http::response(['_embedded'=>['leads'=>array_map(fn($id)=>['id'=>$id],range(1,502))]]);
            $this->assertSame('/api/v4/leads',$path);
            $this->assertSame('250',$query['limit']);
            return Http::response(['_embedded'=>['leads'=>array_map(fn($id)=>['id'=>(int)$id,'pipeline_id'=>10,'status_id'=>20],$query['filter']['id'])]]);
        });
        $result = $this->fetch(['source'=>'lead','lead_id'=>99,'exclude_lead_id'=>99]);
        $this->assertSame(501,$result['output']['count']);
        $this->assertSame(2,$result['output']['contact_id']);
        $this->assertNotContains(99,array_column($result['output']['items'],'id'));
        Http::assertSentCount(5);
    }
    public function test_contact_without_leads_does_not_query_all_account_leads(): void
    {
        Http::fake(['*'=>Http::response(['id'=>10,'_embedded'=>['leads'=>[]]])]);
        $this->assertSame(0,$this->fetch(['source'=>'contact','contact_id'=>10])['output']['count']);
        Http::assertSentCount(1);
    }
    public function test_a_contact_over_the_safety_limit_fails_instead_of_returning_a_partial_list(): void
    {
        Http::fake(['*'=>Http::response(['_embedded'=>['leads'=>array_map(fn($id)=>['id'=>$id],range(1,5001))]])]);
        $this->expectException(\RuntimeException::class);
        $this->fetch(['source'=>'contact','contact_id'=>10]);
    }
}
