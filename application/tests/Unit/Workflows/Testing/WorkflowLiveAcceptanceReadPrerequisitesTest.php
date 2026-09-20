<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use App\Services\Workflows\Testing\WorkflowLiveAcceptance;
use App\Services\Workflows\WorkflowAmoReadCatalog;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class WorkflowLiveAcceptanceReadPrerequisitesTest extends TestCase
{
    private static function ids(): array { return ['leads'=>101,'contacts'=>201,'companies'=>0,'tasks'=>301,'pipeline_id'=>10]; }

    public function test_visible_families_expand_list_id_and_every_note_variant_using_canonical_definitions(): void
    {
        $definitions=WorkflowAmoReadCatalog::operations();
        $visible=[
            'leads.list'=>['name'=>'Получить','path'=>'not-an-endpoint','variants'=>['leads.list'=>'Список','leads.one'=>'По ID']],
            'leads.notes.all'=>['name'=>'Примечания','variants'=>[
                'leads.notes.all'=>'Все','leads.notes.one'=>'По ID','leads.notes'=>'По сущности','leads.notes.entity.one'=>'По сущности и ID',
            ]],
        ];
        $expanded=WorkflowLiveAcceptance::visibleReadOperations($visible,$definitions);
        $this->assertSame(['leads.list','leads.one','leads.notes.all','leads.notes.one','leads.notes','leads.notes.entity.one'],array_keys($expanded));
        foreach ($expanded as $operation=>$definition) $this->assertSame($definitions[$operation],$definition);
        $this->assertArrayNotHasKey('account',$expanded);
    }

    public function test_legacy_ungrouped_catalog_remains_supported(): void
    {
        $definitions=WorkflowAmoReadCatalog::operations();
        $visible=['leads.list'=>$definitions['leads.list'],'leads.one'=>$definitions['leads.one']];
        $this->assertSame($visible,WorkflowLiveAcceptance::visibleReadOperations($visible,$definitions));
    }

    public function test_unknown_visible_variant_fails_instead_of_silently_reducing_coverage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing its endpoint definition');
        WorkflowLiveAcceptance::visibleReadOperations(['family'=>['variants'=>['new.unregistered'=>'New']]],[]);
    }

    #[DataProvider('directOperations')]
    public function test_list_queries_need_no_arbitrary_entity_and_subscriptions_use_the_existing_lead(string $operation,?int $id): void
    {
        $resolved=WorkflowLiveAcceptance::resolveReadPrerequisites($operation,WorkflowAmoReadCatalog::operations()[$operation],self::ids(),
            static function():never { throw new RuntimeException('No prerequisite lookup should be sent'); });
        $this->assertSame('ready',$resolved['status']);
        if ($id!==null) $this->assertSame($id,$resolved['config']['id']);
        $this->assertStringNotContainsString('{',WorkflowAmoReadCatalog::build($resolved['config'])['path']);
    }

    public static function directOperations(): array
    {
        return [['leads.subscriptions',101],['customers.list',null],['customers.tags',null],['customers.custom_fields',null],['customers.notes.all',null]];
    }

    #[DataProvider('catalogOperations')]
    public function test_missing_catalog_stops_before_requesting_any_child_path(string $operation): void
    {
        $seen=[];
        $resolved=WorkflowLiveAcceptance::resolveReadPrerequisites($operation,WorkflowAmoReadCatalog::operations()[$operation],self::ids(),
            static function(string $path) use(&$seen):array { $seen[]=$path; return ['status'=>'empty','id'=>null]; });
        $this->assertSame('skipped',$resolved['status']);
        $this->assertSame(['/api/v4/catalogs'],$seen);
    }

    public static function catalogOperations(): array { return [['elements.one'],['catalog_fields.one'],['elements.list']]; }

    public function test_catalog_and_child_identifiers_are_bound_in_order(): void
    {
        $seen=[];
        $resolved=WorkflowLiveAcceptance::resolveReadPrerequisites('elements.one',WorkflowAmoReadCatalog::operations()['elements.one'],self::ids(),
            static function(string $path) use(&$seen):array { $seen[]=$path; return ['status'=>'available','id'=>$path==='/api/v4/catalogs'?7:20]; });
        $this->assertSame(['/api/v4/catalogs','/api/v4/catalogs/7/elements'],$seen);
        $this->assertSame('/api/v4/catalogs/7/elements/20',WorkflowAmoReadCatalog::build($resolved['config'])['path']);
    }

    public function test_no_company_id_skips_entity_notes_without_a_zero_or_empty_id_request(): void
    {
        $resolved=WorkflowLiveAcceptance::resolveReadPrerequisites('companies.notes.entity.one',WorkflowAmoReadCatalog::operations()['companies.notes.entity.one'],self::ids(),
            static function():never { throw new RuntimeException('Must not issue a lookup'); });
        $this->assertSame('skipped',$resolved['status']);
    }

    public function test_every_catalog_lookup_and_final_path_is_concrete(): void
    {
        foreach (WorkflowLiveAcceptance::visibleReadOperations() as $operation=>$definition) {
            $resolved=WorkflowLiveAcceptance::resolveReadPrerequisites($operation,$definition,self::ids(),function(string $path):array {
                $this->assertMatchesRegularExpression('~^/api/v4/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D',$path);
                return ['status'=>'available','id'=>42];
            });
            $this->assertContains($resolved['status'],['ready','skipped'],$operation);
            if ($resolved['status']==='ready') $this->assertStringNotContainsString('{',WorkflowAmoReadCatalog::build($resolved['config'])['path']);
        }
    }

    #[DataProvider('lookupFailures')]
    public function test_only_proven_capability_or_missing_data_errors_are_skipped(string $path,int $code,string $detail,bool $proof,string $expected): void
    {
        $error='amoCRM API v4 error: GET '.$path.' returned '.$code.': '.json_encode(['status'=>$code,'detail'=>$detail],JSON_THROW_ON_ERROR);
        $this->assertSame($expected,WorkflowLiveAcceptance::classifyReadPrerequisiteError($path,$error,$proof)['status']);
    }

    public static function lookupFailures(): array
    {
        return [
            ['/api/v4/customers',400,'Error 426.',false,'failed'],
            ['/api/v4/customers',400,'Error 426.',true,'unavailable'],
            ['/api/v4/customers',400,'Other validation error',true,'failed'],
            ['/api/v4/customers',401,'Error 426.',true,'failed'],
            ['/api/v4/customers',500,'Error 426.',true,'failed'],
            ['/api/v4/customers/segments',422,'Customers disabled',false,'unavailable'],
            ['/api/v4/customers/custom_fields',422,'Customers disabled',false,'unavailable'],
            ['/api/v4/customers/custom_fields',500,'Customers disabled',true,'failed'],
            ['/api/v4/customers/segments/custom_fields',422,'Customers disabled',false,'unavailable'],
            ['/api/v4/customers/segments',403,'Customers disabled',false,'failed'],
            ['/api/v4/catalogs',422,'Customers disabled',true,'failed'],
            ['/api/v4/customers/transactions',404,'Transactions not found',false,'empty'],
            ['/api/v4/customers/transactions',404,'Unknown route',false,'failed'],
            ['/api/v4/customers/transactions',500,'Transactions not found',true,'failed'],
        ];
    }

    public function test_customer_node_error_426_requires_independent_disabled_feature_evidence(): void
    {
        $error='amoCRM API v4 вернул ошибку 400 на GET /api/v4/customers: Error 426.';
        $this->assertNull(WorkflowLiveAcceptance::capabilitySkipReason('read:customers.list',$error));
        $this->assertNotNull(WorkflowLiveAcceptance::capabilitySkipReason('read:customers.list',$error,true));
        $this->assertNull(WorkflowLiveAcceptance::capabilitySkipReason('read:customers.list',str_replace('400','500',$error),true));
    }

    public function test_live_read_harness_caches_empty_and_failed_prerequisites_and_never_sends_malformed_paths(): void
    {
        Http::preventStrayRequests();
        $seen=[];
        $client=$this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->method('requestV4')->willReturnCallback(function($method,$path) use(&$seen):array {
            $this->assertSame('GET',$method);
            $this->assertMatchesRegularExpression('~^/api/v4/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D',$path);
            $seen[$path]=($seen[$path]??0)+1;
            if ($path==='/api/v4/customers/segments') throw new RuntimeException('amoCRM API v4 error: GET /api/v4/customers/segments returned 422: {"status":422,"detail":"Customers disabled"}');
            if ($path==='/api/v4/customers') throw new RuntimeException('amoCRM API v4 error: GET /api/v4/customers returned 400: {"status":400,"detail":"Error 426."}');
            return ['_embedded'=>['items'=>[]]];
        });
        $debugger=$this->createMock(WorkflowDebugger::class);
        $debugger->method('executeNode')->willReturnCallback(static function(array $step):array {
            if (($step['config']['operation']??'')==='customers.list') return ['status'=>'failed','results'=>[['status'=>'error','error'=>'amoCRM API v4 вернул ошибку 400 на GET /api/v4/customers: Error 426.']]];
            return ['status'=>'completed','results'=>[['status'=>'completed','output'=>['data'=>isset($step['config']['id'])?['id'=>$step['config']['id']]:[],
                'items'=>[],'count'=>0,'has_more'=>false]]]];
        });
        $this->app->instance(WorkflowDebugger::class,$debugger);
        $path=tempnam(sys_get_temp_dir(),'qa-read-resolution-');
        $runner=new WorkflowLiveAcceptance;
        foreach (['client'=>$client,'account'=>(new Account)->forceFill(['id'=>134,'subdomain'=>'widgetscenario']),
            'source'=>(new Workflow)->forceFill(['id'=>15,'user_id'=>142]),'marker'=>'unit-only','reportPath'=>$path,
            'recurringStatePath'=>'/unit-only-unwritten','leadId'=>101,'contactId'=>201,'tasks'=>[301],
            'report'=>['cases'=>[],'cleanup'=>[],'before'=>['company_ids'=>[]]]] as $key=>$value) (new \ReflectionProperty($runner,$key))->setValue($runner,$value);
        try {
            (new \ReflectionMethod($runner,'exerciseReads'))->invoke($runner);
            $this->assertSame(1,$seen['/api/v4/customers']);
            $this->assertSame(1,$seen['/api/v4/customers/segments']);
            $this->assertSame(1,$seen['/api/v4/catalogs']);
            $report=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
            $this->assertSame([],array_values(array_filter($report['cases'],fn($case)=>$case['status']==='failed')));
            Http::assertNothingSent();
        } finally { unlink($path); }
    }
}
