<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Services\Workflows\Testing\WorkflowLiveAcceptance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkflowRecurringAcceptanceSafetyTest extends TestCase
{
    private static function scope(): array
    {
        return ['lead_id'=>101,'contact_id'=>201,'task_ids'=>[301],
            'hook_url'=>'https://app.example.test/qa-hook','pending_creates'=>[]];
    }

    #[DataProvider('allowed')]
    public function test_only_the_current_qa_fixtures_are_mutable(string $method,string $path,array $body,array $override=[]): void
    {
        WorkflowLiveAcceptance::assertRecurringMutation($method,$path,$body,array_replace(self::scope(),$override));
        $this->addToAssertionCount(1);
    }

    public static function allowed(): array
    {
        return [
            ['PATCH','/api/v4/leads/101',['name'=>'QA']],
            ['PATCH','/api/v4/leads/101',['status_id'=>143]],
            ['PATCH','/api/v4/contacts/201',['name'=>'Original']],
            ['PATCH','/api/v4/tasks/301',['is_completed'=>true]],
            ['POST','/api/v4/leads/101/link',[['to_entity_id'=>201,'to_entity_type'=>'contacts']]],
            ['POST','/api/v4/leads/101/unlink',[['to_entity_id'=>201,'to_entity_type'=>'contacts']]],
            ['DELETE','/api/v4/webhooks',['destination'=>'https://app.example.test/qa-hook']],
            ['POST','/api/v4/leads',[['name'=>'Clever QA recurring fixture','status_id'=>143]],['pending_creates'=>['lead']]],
            ['POST','/api/v4/tasks',[['text'=>'Clever QA recurring task','entity_id'=>101,'entity_type'=>'leads']],['pending_creates'=>['task']]],
        ];
    }

    #[DataProvider('forbidden')]
    public function test_unrelated_records_and_append_only_growth_are_forbidden(string $method,string $path,array $body,array $override=[]): void
    {
        $this->expectException(RuntimeException::class);
        WorkflowLiveAcceptance::assertRecurringMutation($method,$path,$body,array_replace(self::scope(),$override));
    }

    public static function forbidden(): array
    {
        return [
            ['PATCH','/api/v4/leads/999',['status_id'=>143]],
            ['PATCH','/api/v4/leads/101',['status_id'=>123]],
            ['PATCH','/api/v4/contacts/999',['name'=>'QA']],
            ['PATCH','/api/v4/contacts/201',['name'=>'QA','responsible_user_id'=>2]],
            ['PATCH','/api/v4/tasks/999',['is_completed'=>true]],
            ['PATCH','/api/v4/tasks/301',['entity_id'=>999]],
            ['PATCH','/api/v4/companies/201',['name'=>'QA']],
            ['POST','/api/v4/leads/101/notes',[['params'=>['text'=>'QA']]]],
            ['POST','/api/v4/contacts/201/notes',[['params'=>['text'=>'QA']]]],
            ['POST','/api/v4/leads/101/link',[['to_entity_id'=>999,'to_entity_type'=>'contacts']]],
            ['POST','/api/v4/leads/101/unlink',[['to_entity_id'=>201,'to_entity_type'=>'companies']]],
            ['DELETE','/api/v4/webhooks',['destination'=>'https://app.example.test/business-hook']],
            ['POST','/api/v4/leads',[['name'=>'Clever QA recurring fixture','status_id'=>143]]],
            ['POST','/api/v4/leads',[['name'=>'Clever QA recurring fixture','status_id'=>123]],['pending_creates'=>['lead']]],
            ['POST','/api/v4/leads',[['name'=>'Clever QA recurring fixture','status_id'=>143],['name'=>'extra','status_id'=>143]],['pending_creates'=>['lead']]],
            ['POST','/api/v4/tasks',[['text'=>'Clever QA recurring task','entity_id'=>101,'entity_type'=>'leads']]],
            ['POST','/api/v4/tasks',[['text'=>'Clever QA recurring task','entity_id'=>999,'entity_type'=>'leads']],['pending_creates'=>['task']]],
        ];
    }

    public function test_closed_inventory_at_the_limit_is_permitted_without_authorizing_an_eleventh_creation(): void
    {
        WorkflowLiveAcceptance::assertRecurringInventory(array_fill(0,10,['status_id'=>143]),array_fill(0,10,[]),[]);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('badInventory')]
    public function test_open_or_over_capacity_accounts_are_blocked(array $leads,array $contacts,array $companies): void
    {
        $this->expectException(RuntimeException::class);
        WorkflowLiveAcceptance::assertRecurringInventory($leads,$contacts,$companies);
    }

    public static function badInventory(): array
    {
        return [
            [[['status_id'=>123]],[],[]],
            [[['id'=>1]],[],[]],
            [array_fill(0,11,['status_id'=>143]),[],[]],
            [[],array_fill(0,11,[]),[]],
            [[],[],array_fill(0,11,[])],
        ];
    }

    private static function checkpoint(): array
    {
        return ['schema_version'=>1,'phase'=>'ready','source_workflow_id'=>15,'domain'=>'widgetscenario','amo_account_id'=>33098322];
    }

    public function test_a_clean_checkpoint_for_the_exact_account_can_run_again(): void
    {
        WorkflowLiveAcceptance::assertRecurringCheckpoint(self::checkpoint(),15,'widgetscenario',33098322);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('badCheckpoint')]
    public function test_crash_cleanup_failure_and_account_mismatch_fail_closed(array $override): void
    {
        $this->expectException(RuntimeException::class);
        WorkflowLiveAcceptance::assertRecurringCheckpoint(array_replace(self::checkpoint(),$override),15,'widgetscenario',33098322);
    }

    public static function badCheckpoint(): array
    {
        return [[['phase'=>'running']],[['phase'=>'recovery_required']],[['phase'=>null]],
            [['source_workflow_id'=>16]],[['domain'=>'another']],[['amo_account_id'=>999]],[['schema_version'=>2]]];
    }

    #[DataProvider('webhookCases')]
    public function test_webhooks_must_arrive_for_the_correct_entity_and_complete(array $events,bool $expected): void
    {
        $this->assertSame($expected,WorkflowLiveAcceptance::verifyObservedEvent($events,'update_lead',101)['passed']);
    }

    public static function webhookCases(): array
    {
        $good=['run_id'=>1,'status'=>'completed','steps'=>[['status'=>'completed']],
            'normalized'=>['update_lead'=>['items'=>[['id'=>'101']]]]];
        return [
            [[],false],
            [[$good],true],
            [[array_replace($good,['status'=>'failed'])],false],
            [[array_replace($good,['status'=>'running'])],false],
            [[array_replace($good,['steps'=>[]])],false],
            [[array_replace($good,['steps'=>[['status'=>'failed']]])],false],
            [[array_replace($good,['normalized'=>['update_lead'=>['items'=>[['id'=>999]]]]])],false],
            [[array_replace($good,['normalized'=>['status_lead'=>['items'=>[['id'=>101]]]]])],false],
            [[$good,array_replace($good,['run_id'=>2,'status'=>'failed'])],false],
        ];
    }

    #[DataProvider('capabilityErrors')]
    public function test_only_known_account_capabilities_are_skipped(string $id,string $error,bool $expectedSkip): void
    {
        $this->assertSame($expectedSkip,WorkflowLiveAcceptance::capabilitySkipReason($id,$error)!==null);
    }

    public static function capabilityErrors(): array
    {
        return [
            ['read:segments.list','amoCRM API v4 вернул ошибку 422 на GET /api/v4/customers/segments: Customers disabled',true],
            ['read:segment_fields.list','amoCRM API v4 вернул ошибку 422 на GET /api/v4/customers/segments/custom_fields: Customers disabled',true],
            ['read:transactions.list','amoCRM API v4 вернул ошибку 404 на GET /api/v4/customers/transactions: Transactions not found',true],
            ['read:segments.list','HTTP 500 Customers disabled',false],
            ['read:segments.list','amoCRM API v4 вернул ошибку 403 на GET /api/v4/customers/segments: Customers disabled',false],
            ['read:contacts.list','amoCRM API v4 вернул ошибку 422 на GET /api/v4/customers/segments: Customers disabled',false],
            ['read:segments.list','OAuth token expired',false],
        ];
    }
}
