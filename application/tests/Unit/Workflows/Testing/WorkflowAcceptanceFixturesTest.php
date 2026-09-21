<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Services\Workflows\Testing\WorkflowAcceptanceFixtures;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Pure in-memory contract tests. Never boots Laravel or connects to the account. */
final class WorkflowAcceptanceFixturesTest extends TestCase
{
    private function seed(array $overrides = []): array
    {
        return array_replace(['lead_id'=>101,'contact_id'=>201,'original_company_ids'=>[], 'customers_mode'=>'segments'], $overrides);
    }

    public function test_preparation_is_idempotent_and_never_creates_a_contact(): void
    {
        [$fixtures,$world]=$this->harness();
        $first=$fixtures->prepare($this->seed());
        $saved=$fixtures->state();
        $firstPosts=$this->posts($world);

        $this->assertNotEmpty($firstPosts);
        $this->assertNotEmpty($saved['resources']);
        $this->assertNotEmpty($first['lookups']);
        foreach ($firstPosts as $call) $this->assertNotSame('/api/v4/contacts',$call['path']);
        $this->assertCount(1,array_filter($firstPosts,fn($call)=>$call['path']==='/api/v4/companies'));
        $this->assertCount(1,array_filter($firstPosts,fn($call)=>$call['path']==='/api/v4/customers'));
        $this->assertCount(1,array_filter($firstPosts,fn($call)=>$call['path']==='/api/v4/catalogs'));

        [$again]=$this->harness($saved,$world);
        $again->prepare($this->seed(['original_company_ids'=>$this->companyIds($world)]));

        $this->assertCount(count($firstPosts),$this->posts($world));
        $this->assertNull($again->state()['pending']??null);
        $this->assertSame([], $again->state()['created_company_ids']);
    }

    public function test_each_post_has_a_durable_pending_checkpoint_before_the_request(): void
    {
        [$fixtures,$world]=$this->harness();
        $fixtures->prepare($this->seed());

        foreach ($this->posts($world) as $call) {
            $pending=$call['checkpoint']['pending']??null;
            $this->assertIsArray($pending);
            $this->assertSame('POST',$pending['method']);
            $this->assertSame($call['path'],$pending['path']);
            $this->assertSame($call['body'],$pending['body']);
            WorkflowAcceptanceFixtures::assertMutation('POST',$call['path'],$call['body'],$call['checkpoint']);
        }
        $this->assertNull($fixtures->state()['pending']??null);
    }

    public function test_absent_contact_does_not_trigger_contact_creation_or_note_creation(): void
    {
        [$fixtures,$world]=$this->harness();
        $fixtures->prepare($this->seed(['contact_id'=>0]));

        $this->assertArrayNotHasKey('note_contact',$fixtures->state()['resources']);
        foreach($this->posts($world) as $call) $this->assertFalse(str_starts_with($call['path'],'/api/v4/contacts'));
    }

    public function test_checkpoint_failure_before_creation_performs_no_mutation(): void
    {
        [$fixtures,$world]=$this->harness(checkpointFailure: static fn(array $state): bool => !empty($state['pending']));

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $this->assertSame([],$this->posts($world));
    }

    public function test_ambiguous_creation_is_not_replayed_and_blocks_the_next_run(): void
    {
        [$fixtures,$world]=$this->harness(intercept: static function(array $call): ?array {
            if ($call['method']==='POST') return [];
            return null;
        });

        $this->fails(fn()=> $fixtures->prepare($this->seed()));
        $this->assertCount(1,$this->posts($world));
        $state=$fixtures->state();
        $this->assertNotEmpty($state['pending']);
        $callsBefore=count($world->calls);
        [$again]=$this->harness($state,$world);

        $this->fails(fn()=> $again->prepare($this->seed()));

        $this->assertCount($callsBefore,$world->calls);
        $this->assertCount(1,$this->posts($world));
    }

    public function test_transport_failure_after_post_keeps_pending_and_does_not_retry(): void
    {
        [$fixtures,$world]=$this->harness(intercept: static function(array $call): ?array {
            if ($call['method']==='POST') throw new RuntimeException('Transport outcome unknown');
            return null;
        });

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $this->assertCount(1,$this->posts($world));
        $this->assertNotEmpty($fixtures->state()['pending']);
    }

    public function test_company_cap_is_checked_before_creating_an_eleventh_company(): void
    {
        [$fixtures,$world]=$this->harness();
        for($id=1;$id<=10;$id++) $world->records['/api/v4/companies/'.$id]=['id'=>$id,'name'=>'Existing company '.$id];

        $this->fails(fn()=> $fixtures->prepare($this->seed(['original_company_ids'=>range(1,10)])));

        $this->assertSame([],$this->posts($world));
    }

    public function test_customer_cap_blocks_an_eleventh_customer(): void
    {
        [$fixtures,$world]=$this->harness();
        for($id=1;$id<=10;$id++) $world->records['/api/v4/customers/'.$id]=['id'=>$id,'name'=>'Existing customer '.$id];

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $this->assertSame([],array_values(array_filter($this->posts($world),fn($call)=>$call['path']==='/api/v4/customers')));
    }

    public function test_catalog_cap_blocks_an_eleventh_catalog_without_recording_an_attempt(): void
    {
        [$fixtures,$world]=$this->harness();
        for($id=1;$id<=10;$id++) $world->records['/api/v4/catalogs/'.$id]=['id'=>$id,'name'=>'Existing catalog '.$id,'type'=>'regular'];

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $this->assertNull($fixtures->state()['pending']);
        $this->assertSame([],array_values(array_filter($this->posts($world),fn($call)=>$call['path']==='/api/v4/catalogs')));
    }

    public function test_segment_cap_blocks_a_hundred_and_first_segment_without_recording_an_attempt(): void
    {
        [$fixtures,$world]=$this->harness();
        for($id=1;$id<=100;$id++) $world->records['/api/v4/customers/segments/'.$id]=['id'=>$id,'name'=>'Existing segment '.$id];

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $this->assertNull($fixtures->state()['pending']);
        $this->assertSame([],array_values(array_filter($this->posts($world),fn($call)=>$call['path']==='/api/v4/customers/segments')));
    }

    public function test_segment_field_inventory_at_the_cap_reuses_a_readable_existing_field(): void
    {
        [$fixtures,$world]=$this->harness();
        for($id=1;$id<=30;$id++) $world->records['/api/v4/customers/segments/custom_fields/'.$id]=['id'=>$id,'name'=>'Existing segment field '.$id,'type'=>'text'];

        $fixtures->prepare($this->seed());

        $this->assertFalse($fixtures->state()['resources']['segment_field']['owned']);
        $this->assertSame([],array_values(array_filter($this->posts($world),fn($call)=>$call['path']==='/api/v4/customers/segments/custom_fields')));
    }

    public function test_existing_marker_company_can_be_adopted_at_the_cap_without_creating_an_eleventh(): void
    {
        [$fixtures,$world]=$this->harness();
        for($id=1;$id<=10;$id++) $world->records['/api/v4/companies/'.$id]=['id'=>$id,'name'=>$id===10?'Clever QA recurring fixture company':'Existing company '.$id];

        $fixtures->prepare($this->seed(['original_company_ids'=>range(1,10)]));

        $this->assertSame(10,$fixtures->state()['resources']['company']['id']);
        $this->assertSame([],$fixtures->state()['created_company_ids']);
        $this->assertSame([],array_values(array_filter($this->posts($world),fn($call)=>$call['path']==='/api/v4/companies')));
    }

    public function test_existing_fields_and_common_notes_are_borrowed_without_creating_duplicates(): void
    {
        [$fixtures,$world]=$this->harness();
        $world->records['/api/v4/customers/custom_fields/51']=['id'=>51,'name'=>'Existing customer field','type'=>'text'];
        $world->records['/api/v4/customers/segments/custom_fields/61']=['id'=>61,'name'=>'Existing segment field','type'=>'text'];
        $world->records['/api/v4/leads/101/notes/71']=['id'=>71,'entity_id'=>101,'note_type'=>'common','params'=>['text'=>'Existing lead note']];
        $world->records['/api/v4/contacts/201/notes/72']=['id'=>72,'entity_id'=>201,'note_type'=>'common','params'=>['text'=>'Existing contact note']];

        $result=$fixtures->prepare($this->seed());

        foreach(['customer_field'=>51,'segment_field'=>61,'note_lead'=>71,'note_contact'=>72] as $key=>$id) {
            $resource=$fixtures->state()['resources'][$key];
            $this->assertSame($id,$resource['id']);
            $this->assertFalse($resource['owned']);
            $this->assertSame($id,$result['lookups'][$resource['collection']]);
            $this->assertSame([],array_values(array_filter($this->posts($world),fn($call)=>$call['path']===$resource['collection'])));
        }
    }

    public function test_duplicate_marked_companies_fail_before_mutation_instead_of_arbitrary_adoption(): void
    {
        [$fixtures,$world]=$this->harness();
        foreach([11,12] as $id) $world->records['/api/v4/companies/'.$id]=['id'=>$id,'name'=>'Clever QA recurring fixture company'];

        $this->fails(fn()=> $fixtures->prepare($this->seed(['original_company_ids'=>[11,12]])));

        $this->assertSame([],$this->posts($world));
    }

    public function test_changed_seed_parent_blocks_preparation_without_any_requests(): void
    {
        [$fixtures,$world]=$this->harness();
        $fixtures->prepare($this->seed());
        $before=count($world->calls);
        [$again]=$this->harness($fixtures->state(),$world);

        $this->fails(fn()=> $again->prepare($this->seed(['contact_id'=>999,'original_company_ids'=>$this->companyIds($world)])));

        $this->assertCount($before,$world->calls);
    }

    public function test_transaction_ambiguous_embedded_key_is_reconciled_with_the_exact_parent_and_marker(): void
    {
        [$fixtures,$world]=$this->harness(intercept: static function(array $call,object $world): ?array {
            if($call['method']!=='POST' || !preg_match('#^/api/v4/customers/([0-9]+)/transactions$#',$call['path'],$matches)) return null;
            $record=$call['body'][0]+['id'=>++$world->nextId,'customer_id'=>(int)$matches[1]];
            $world->records[$call['path'].'/'.$record['id']]=$record;
            return ['_embedded'=>['customers'=>[['id'=>(int)$matches[1]]]]];
        });

        $fixtures->prepare($this->seed());

        $transaction=$fixtures->state()['resources']['transaction'];
        $customer=$fixtures->state()['resources']['customer'];
        $this->assertNotSame($customer['id'],$transaction['id']);
        $this->assertSame($customer['id'],$transaction['expected']['customer_id']);
        $this->assertCount(1,array_filter($this->posts($world),fn($call)=>str_ends_with($call['path'],'/transactions')));
        $this->assertNull($fixtures->state()['pending']);
    }

    public function test_transaction_reconciliation_never_adopts_another_customers_record(): void
    {
        [$fixtures,$world]=$this->harness(intercept: static function(array $call,object $world): ?array {
            if($call['method']!=='POST' || !str_ends_with($call['path'],'/transactions')) return null;
            $record=$call['body'][0]+['id'=>++$world->nextId,'customer_id'=>999];
            $world->records[$call['path'].'/'.$record['id']]=$record;
            return ['_embedded'=>['customers'=>[['id'=>999]]]];
        });

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $this->assertSame('transaction',$fixtures->state()['pending']['key']);
        $this->assertCount(1,array_filter($this->posts($world),fn($call)=>str_ends_with($call['path'],'/transactions')));
    }

    public function test_only_exact_empty_transactions_404_allows_the_first_transaction_fixture(): void
    {
        [$fixtures,$world]=$this->harness(intercept: static function(array $call): ?array {
            if($call['method']==='GET' && preg_match('#^/api/v4/customers/[0-9]+/transactions$#',$call['path'])) {
                throw new RuntimeException('amoCRM API v4 error: GET '.$call['path'].' returned 404: {"detail":"Transactions not found"}');
            }
            return null;
        });

        $fixtures->prepare($this->seed());

        $this->assertGreaterThan(0,$fixtures->state()['resources']['transaction']['id']);
        $this->assertCount(1,array_filter($this->posts($world),fn($call)=>str_ends_with($call['path'],'/transactions')));
    }

    public function test_an_unrelated_transactions_404_cannot_authorize_creation(): void
    {
        [$fixtures,$world]=$this->harness(intercept: static function(array $call): ?array {
            if($call['method']==='GET' && preg_match('#^/api/v4/customers/[0-9]+/transactions$#',$call['path'])) {
                throw new RuntimeException('amoCRM API v4 error: GET '.$call['path'].' returned 404: {"detail":"Customer not found"}');
            }
            return null;
        });

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $this->assertSame([],array_values(array_filter($this->posts($world),fn($call)=>str_ends_with($call['path'],'/transactions'))));
    }

    public function test_company_created_before_later_failure_remains_in_recovery_inventory(): void
    {
        [$fixtures,$world]=$this->harness(intercept: static function(array $call): ?array {
            if ($call['method']==='POST' && $call['path']==='/api/v4/customers') throw new RuntimeException('Customers request failed');
            return null;
        });

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $state=$fixtures->state();
        $companyId=(int)($state['resources']['company']['id']??0);
        $this->assertGreaterThan(0,$companyId);
        $this->assertSame([$companyId],$state['created_company_ids']);
        $this->assertSame([$companyId],$world->checkpoint['created_company_ids']);
        $this->assertNotEmpty($state['pending']);
    }

    public function test_company_id_is_persisted_even_when_its_first_readback_fails(): void
    {
        [$fixtures,$world]=$this->harness(intercept: static function(array $call): ?array {
            if ($call['method']==='GET' && preg_match('#^/api/v4/companies/[0-9]+$#',$call['path'])) throw new RuntimeException('Readback unavailable');
            return null;
        });

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $this->assertCount(1,$fixtures->state()['created_company_ids']);
        $this->assertSame($fixtures->state()['created_company_ids'],$world->checkpoint['created_company_ids']);
        $this->assertNotEmpty($fixtures->state()['pending']);
    }

    public function test_record_with_wrong_id_cannot_be_accepted_as_creation_readback(): void
    {
        [$fixtures,$world]=$this->harness(intercept: static function(array $call): ?array {
            if ($call['method']==='GET' && preg_match('#^/api/v4/companies/[0-9]+$#',$call['path'])) return ['id'=>999,'name'=>'Unrelated company'];
            return null;
        });

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $this->assertNotEmpty($fixtures->state()['pending']);
        $this->assertCount(1,$this->posts($world));
    }

    public function test_existing_owned_fixture_renamed_by_someone_else_is_not_mutated_or_replaced(): void
    {
        [$fixtures,$world]=$this->harness();
        $fixtures->prepare($this->seed());
        $state=$fixtures->state();
        $resource=$state['resources']['company'];
        $world->records[$resource['collection'].'/'.$resource['id']]['name']='Customer-owned company now';
        $before=count($this->posts($world));
        [$again]=$this->harness($state,$world);

        $this->fails(fn()=> $again->prepare($this->seed(['original_company_ids'=>$this->companyIds($world)])));

        $this->assertCount($before,$this->posts($world));
    }

    public function test_unknown_pending_operation_blocks_all_requests(): void
    {
        [$fixtures,$world]=$this->harness(['schema_version'=>1,'resources'=>[], 'pending'=>['key'=>'foreign','method'=>'POST','path'=>'/api/v4/contacts','body'=>[['name'=>'No']]],'created_company_ids'=>[]]);

        $this->fails(fn()=> $fixtures->prepare($this->seed()));

        $this->assertSame([],$world->calls);
    }

    public function test_saved_child_readback_must_still_belong_to_its_expected_parent(): void
    {
        [$fixtures,$world]=$this->harness();
        $fixtures->prepare($this->seed());
        $state=$fixtures->state();
        $note=$state['resources']['note_company'];
        $world->records[$note['collection'].'/'.$note['id']]['entity_id']=999;
        $before=count($this->posts($world));
        [$again]=$this->harness($state,$world);

        $this->fails(fn()=> $again->prepare($this->seed(['original_company_ids'=>$this->companyIds($world)])));

        $this->assertCount($before,$this->posts($world));
    }

    public function test_guard_will_not_create_children_under_an_unowned_company(): void
    {
        [$fixtures,$world]=$this->harness();
        $fixtures->prepare($this->seed());
        $noteCalls=array_values(array_filter($this->posts($world),fn($call)=>(bool)preg_match('#/companies/[0-9]+/notes$#',$call['path'])));
        $this->assertCount(1,$noteCalls);
        $call=$noteCalls[0];
        $state=$call['checkpoint'];
        $state['resources']['company']['owned']=false;

        $this->fails(fn()=> WorkflowAcceptanceFixtures::assertMutation('POST',$call['path'],$call['body'],$state));
    }

    public function test_guard_rejects_changed_body_and_foreign_parent_even_with_a_forged_pending_record(): void
    {
        [$fixtures,$world]=$this->harness();
        $fixtures->prepare($this->seed());
        $state=$fixtures->state();
        $cases=[
            ['contact','/api/v4/contacts',[['name'=>'Clever QA recurring forbidden']]],
            ['note_lead','/api/v4/leads/999/notes',[['note_type'=>'common','params'=>['text'=>'Clever QA recurring fixture']]]],
            ['note_contact','/api/v4/contacts/999/notes',[['note_type'=>'common','params'=>['text'=>'Clever QA recurring fixture']]]],
            ['element','/api/v4/catalogs/999/elements',[['name'=>'Clever QA recurring fixture']]],
            ['transaction','/api/v4/customers/999/transactions',[['price'=>1]]],
            ['company','/api/v4/companies',[['name'=>'Ordinary customer company']]],
            ['company','/api/v4/companies',[['name'=>'Clever QA recurring fixture'],['name'=>'Clever QA recurring extra']]],
            ['company','/api/v4/companies',[['name'=>'Clever QA recurring fixture','_embedded'=>['contacts'=>[['name'=>'Forbidden new contact']]]]]],
        ];
        foreach($cases as [$key,$path,$body]) {
            $forged=$state;
            $forged['pending']=['key'=>$key,'method'=>'POST','path'=>$path,'body'=>$body];
            $this->fails(fn()=> WorkflowAcceptanceFixtures::assertMutation('POST',$path,$body,$forged));
        }
        foreach($this->posts($world) as $call) {
            $changed=$call['body'];
            $changed['unexpected']='not in durable intent';
            $this->fails(fn()=> WorkflowAcceptanceFixtures::assertMutation('POST',$call['path'],$changed,$call['checkpoint']));
            $this->fails(fn()=> WorkflowAcceptanceFixtures::assertMutation('DELETE',$call['path'],$call['body'],$call['checkpoint']));
        }
    }

    /** Generic amoCRM-shaped in-memory store; callback can force precise failure points. */
    private function harness(array $initial=[],?object $world=null,?callable $intercept=null,?callable $checkpointFailure=null): array
    {
        $world??=(object)['calls'=>[],'snapshots'=>[],'events'=>[],'checkpoint'=>$initial,'nextId'=>1000,'records'=>[
            '/api/v4/leads/101'=>['id'=>101,'name'=>'Clever QA recurring fixture','status_id'=>143],
            '/api/v4/contacts/201'=>['id'=>201,'name'=>'Existing contact'],
        ]];
        $request=function(string $method,string $path,array $body=[],array $query=[]) use($world,$intercept): array {
            $call=compact('method','path','body','query')+['checkpoint'=>$world->checkpoint];
            $world->calls[]=$call;
            if($intercept) { $override=$intercept($call,$world); if($override!==null) return $override; }
            if($method==='GET') {
                if(isset($world->records[$path])) return $world->records[$path];
                if(preg_match('#/[0-9]+$#',$path)) throw new RuntimeException('Fixture GET returned404: '.$path);
                $rows=[];
                foreach($world->records as $storedPath=>$record) if(dirname($storedPath)===$path) $rows[]=$record;
                return ['_embedded'=>[$this->collectionKey($path)=>$rows]];
            }
            if($method!=='POST') throw new RuntimeException('Unexpected mutation '.$method.' '.$path);
            $record=array_is_list($body)?($body[0]??[]):$body;
            $record['id']=++$world->nextId;
            if(preg_match('#/api/v4/(leads|contacts|companies|customers)/([0-9]+)/notes$#',$path,$matches)) $record['entity_id']=(int)$matches[2];
            if(preg_match('#/api/v4/catalogs/([0-9]+)/(elements|custom_fields)$#',$path,$matches)) $record['catalog_id']=(int)$matches[1];
            if(preg_match('#/api/v4/customers/([0-9]+)/transactions$#',$path,$matches)) $record['customer_id']=(int)$matches[1];
            $world->records[$path.'/'.$record['id']]=$record;
            if($path==='/api/v4/customers/segments') return $record;
            return ['_embedded'=>[$this->collectionKey($path)=>[$record]]];
        };
        $checkpoint=function(array $state) use($world,$checkpointFailure): void {
            if($checkpointFailure && $checkpointFailure($state)) throw new RuntimeException('Checkpoint write failed');
            $world->checkpoint=$state;
            $world->snapshots[]=$state;
        };
        $fixtures=new WorkflowAcceptanceFixtures($request,$checkpoint,static function(array $event) use($world): void { $world->events[]=$event; },$initial);
        return [$fixtures,$world];
    }

    private function collectionKey(string $path): string { return basename($path); }
    private function posts(object $world): array { return array_values(array_filter($world->calls,fn($call)=>$call['method']==='POST')); }
    private function companyIds(object $world): array
    {
        $ids=[];
        foreach($world->records as $path=>$record) if(dirname($path)==='/api/v4/companies') $ids[]=$record['id'];
        return $ids;
    }
    private function fails(callable $operation): void
    {
        try { $operation(); }
        catch(RuntimeException) { $this->addToAssertionCount(1); return; }
        $this->fail('Operation must fail closed.');
    }
}
