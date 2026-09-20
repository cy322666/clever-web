<?php

namespace Tests\Unit\Workflows\Testing;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\Testing\WorkflowLiveAcceptance;
use App\Workflows\Engine\WorkflowDebugger;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowLiveAcceptanceNodeFailureTest extends TestCase
{
    #[DataProvider('failureCases')]
    public function test_only_the_expected_validation_error_without_mutations_passes(string $id, string $error, array $exchanges, bool $expected): void
    {
        $debugger=$this->createMock(WorkflowDebugger::class);
        $debugger->expects($this->once())->method('executeNode')->willReturn([
            'status'=>'failed', 'error'=>$error,
            'results'=>[['status'=>'error','error'=>$error,'output'=>['amo_exchange'=>$exchanges]]],
        ]);
        $this->app->instance(WorkflowDebugger::class,$debugger);
        $runner=new WorkflowLiveAcceptance;
        $file=tempnam(sys_get_temp_dir(),'workflow-validation-');
        foreach ([
            'account'=>(new Account)->forceFill(['id'=>1,'subdomain'=>'contract-only']),
            'source'=>(new Workflow)->forceFill(['id'=>15,'user_id'=>1]),
            'marker'=>'contract-only', 'reportPath'=>$file, 'report'=>['cases'=>[]],
        ] as $key=>$value) (new \ReflectionProperty($runner,$key))->setValue($runner,$value);
        try {
            (new \ReflectionMethod($runner,'node'))->invoke($runner,$id,'amocrm_add_note',[],null,true);
            $report=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR);
            $this->assertSame($expected?'passed':'failed',$report['cases'][0]['status']);
            $this->assertSame($expected,$report['cases'][0]['verification']['passed']);
        } finally {
            unlink($file);
        }
    }

    public static function failureCases(): array
    {
        return [
            'missing note'=>['missing_note_text','Не заполнен текст примечания.',[],true],
            'invalid bot'=>['invalid_salesbot_config','Укажите корректные ID Salesbot и сущности.',[],true],
            'invalid contact'=>['invalid_contact_id','Не найден ID контакта amoCRM.',[],true],
            'invalid JSON'=>['invalid_update_json','Некорректное JSON-тело: Syntax error',[],true],
            'transport error is not validation'=>['missing_note_text','HTTP 503: upstream unavailable',[],false],
            'billing error is not validation'=>['invalid_salesbot_config','Оплаченный период завершён.',[],false],
            'unknown negative case cannot pass'=>['new_negative_case','Не заполнен текст примечания.',[],false],
            'validation after mutation fails'=>['missing_note_text','Не заполнен текст примечания.',[['request'=>['method'=>'POST','path'=>'/api/v4/leads/101/notes']]],false],
            'read-only exchange allowed'=>['missing_note_text','Не заполнен текст примечания.',[['request'=>['method'=>'GET','path'=>'/api/v4/account']]],true],
            'unidentified exchange fails closed'=>['missing_note_text','Не заполнен текст примечания.',[['response'=>['code'=>200]]],false],
        ];
    }
}
