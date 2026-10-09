<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use App\Services\Workflows\Testing\WorkflowLiveAcceptance;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class WorkflowRecurringLeadPreparationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/qa-lead-preparation-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) unlink($file);
        rmdir($this->directory);
        parent::tearDown();
    }

    private function set(WorkflowLiveAcceptance $runner, string $property, mixed $value): void
    {
        (new ReflectionProperty($runner, $property))->setValue($runner, $value);
    }

    private function runner(array $leads): WorkflowLiveAcceptance
    {
        $runner = new WorkflowLiveAcceptance;
        foreach ([
            'account'=>(new Account)->forceFill(['id'=>134, 'subdomain'=>'widgetscenario']),
            'source'=>(new Workflow)->forceFill(['id'=>15, 'user_id'=>142]),
            'reportPath'=>$this->directory.'/report.json', 'marker'=>'unit-only',
            'recurringStatePath'=>$this->directory.'/state.json', 'recurringStateStarted'=>true,
            'report'=>['account'=>['amo_account_id'=>33098322], 'before'=>['company_ids'=>[]]],
            'workflowsPaused'=>true, 'originalLeads'=>$leads,
        ] as $property=>$value) $this->set($runner, $property, $value);
        return $runner;
    }

    private function prepare(WorkflowLiveAcceptance $runner): void
    {
        (new ReflectionMethod($runner, 'prepareRecurringLeads'))->invoke($runner);
    }

    private function lead(int $id, int $status): array
    {
        return ['id'=>$id, 'status_id'=>$status, 'name'=>'Existing test deal'];
    }

    public function test_closes_only_open_existing_leads_and_persists_intent_before_writing(): void
    {
        $leads = [$this->lead(101, 123), $this->lead(102, 142), $this->lead(103, 143)];
        $runner = $this->runner($leads);
        $writes = [];
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->method('requestV4')->willReturnCallback(function ($method, $path, $body = []) use (&$leads, &$writes, $runner): array {
            if ($method === 'GET' && $path === '/api/v4/leads') return ['_embedded'=>['leads'=>$leads]];
            $this->assertSame('/api/v4/leads/101', $path);
            if ($method === 'GET') return $leads[0];
            $this->assertSame('PATCH', $method);
            $this->assertSame(['status_id'=>143], $body);
            $state = json_decode(file_get_contents($this->directory.'/state.json'), true);
            $this->assertSame(101, $state['recovery']['pre_run_lead_closure_id']);
            $saved = json_decode(file_get_contents($this->directory.'/report.json'), true);
            $this->assertSame([['id'=>101, 'status_id'=>123]], $saved['preparation']['close_existing_leads']['before']);
            WorkflowLiveAcceptance::assertRecurringMutation($method, $path, $body, [
                'pre_run_lead_closure_id'=>(new ReflectionProperty($runner, 'preRunLeadClosureId'))->getValue($runner),
            ]);
            $writes[] = $path;
            $leads[0]['status_id'] = 143;
            return ['id'=>101];
        });
        $this->set($runner, 'client', $client);
        $this->prepare($runner);
        $report = json_decode(file_get_contents($this->directory.'/report.json'), true);
        $this->assertSame([101], $report['preparation']['close_existing_leads']['closed_ids']);
        $this->assertTrue($report['preparation']['close_existing_leads']['verified']);
        $this->prepare($runner);
        $this->assertSame(['/api/v4/leads/101'], $writes);
        $this->assertSame([143, 142, 143], array_column($leads, 'status_id'));
        $report = json_decode(file_get_contents($this->directory.'/report.json'), true);
        $this->assertTrue($report['preparation']['close_existing_leads']['verified']);
        $state = json_decode(file_get_contents($this->directory.'/state.json'), true);
        $this->assertSame(0, $state['recovery']['pre_run_lead_closure_id']);
    }

    #[DataProvider('unauthorizedScope')]
    public function test_foreign_scope_or_unpaused_workflows_cannot_close_leads(string $property, mixed $value): void
    {
        $runner = $this->runner([$this->lead(101, 123)]);
        if ($property === 'account') $value = (new Account)->forceFill($value);
        if ($property === 'source') $value = (new Workflow)->forceFill($value);
        $this->set($runner, $property, $value);
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->expects($this->never())->method('requestV4');
        $this->set($runner, 'client', $client);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not authorized or workflows are not paused');
        $this->prepare($runner);
    }

    public static function unauthorizedScope(): array
    {
        return [
            ['account', ['id'=>134, 'subdomain'=>'another']],
            ['source', ['id'=>16, 'user_id'=>142]],
            ['report', ['account'=>['amo_account_id'=>999]]],
            ['recurringStatePath', null],
            ['workflowsPaused', false],
        ];
    }

    #[DataProvider('changedInventory')]
    public function test_changed_or_invalid_inventory_stops_before_any_patch(array $inventory): void
    {
        $runner = $this->runner([$this->lead(101, 123)]);
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->expects($this->once())->method('requestV4')->with('GET', '/api/v4/leads', [], ['limit'=>250, 'page'=>1])
            ->willReturn(['_embedded'=>['leads'=>$inventory]]);
        $this->set($runner, 'client', $client);
        $this->expectException(RuntimeException::class);
        $this->prepare($runner);
    }

    public static function changedInventory(): array
    {
        return [
            [[]],
            [[['id'=>999, 'status_id'=>123]]],
            [[['id'=>101]]],
            [array_fill(0, 11, ['id'=>101, 'status_id'=>123])],
            [[['id'=>101, 'status_id'=>123], ['id'=>102, 'status_id'=>123]]],
        ];
    }

    public function test_missing_cleanup_scope_keeps_preparation_disabled(): void
    {
        config(['workflow_acceptance.lead_cleanup_scope'=>[]]);
        $runner = $this->runner([$this->lead(101, 123)]);
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->expects($this->never())->method('requestV4');
        $this->set($runner, 'client', $client);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not authorized');
        $this->prepare($runner);
    }

    public function test_report_write_failure_stops_before_any_crm_mutation(): void
    {
        $lead = $this->lead(101, 123);
        $runner = $this->runner([$lead]);
        $this->set($runner, 'reportPath', $this->directory);
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->expects($this->once())->method('requestV4')->with('GET', '/api/v4/leads', [], ['limit'=>250, 'page'=>1])
            ->willReturn(['_embedded'=>['leads'=>[$lead]]]);
        $this->set($runner, 'client', $client);
        $this->expectException(\Throwable::class);
        $this->prepare($runner);
    }

    public function test_unconfirmed_closure_keeps_the_exact_pending_id_for_recovery(): void
    {
        $lead = $this->lead(101, 123);
        $runner = $this->runner([$lead]);
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->method('requestV4')->willReturnCallback(fn($method, $path) => $path === '/api/v4/leads'
            ? ['_embedded'=>['leads'=>[$lead]]] : ($method === 'PATCH' ? ['id'=>101] : $lead));
        $this->set($runner, 'client', $client);
        try {
            $this->prepare($runner);
            $this->fail('An unconfirmed status must stop preparation');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('could not confirm status 143', $error->getMessage());
            $state = json_decode(file_get_contents($this->directory.'/state.json'), true);
            $this->assertSame(101, $state['recovery']['pre_run_lead_closure_id']);
        }
    }
}
