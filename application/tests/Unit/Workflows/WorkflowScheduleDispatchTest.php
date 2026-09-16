<?php

namespace Tests\Unit\Workflows;

use App\Models\Workflows\Workflow;
use Illuminate\Support\Facades\Bus;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Leek\FilamentWorkflows\Services\WorkflowRateLimiter;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowScheduleDispatchTest extends TestCase
{
    public function test_matching_rules_dispatch_once_per_start_and_per_minute(): void
    {
        WorkflowListDatabase::prepare();
        config(['cache.default' => 'array']);
        Bus::fake();
        $this->travelTo(now()->setDate(2026, 9, 11)->setTime(9, 0));
        $config = ['timezone' => config('app.timezone'), 'rules' => [['frequency' => 'daily', 'time' => '09:00'], ['frequency' => 'hourly']]];
        (new Workflow)->forceFill(['name' => 'Два расписания', 'user_id' => 1, 'is_active' => true, 'trigger_type' => 'manual', 'definition' => [
            'trigger' => ['type' => 'manual'], 'additional_triggers' => [
                ['id' => 'trigger:first', 'type' => 'schedule', 'config' => $config], ['id' => 'trigger:second', 'type' => 'schedule', 'config' => $config],
            ], 'actions' => [['id' => 'x', 'type' => 'amocrm_add_note', 'config' => []]],
        ]])->saveQuietly();
        $rate = $this->createMock(WorkflowRateLimiter::class);
        $rate->method('canExecute')->willReturn(['allowed' => true]);
        $this->app->instance(WorkflowRateLimiter::class, $rate);
        $executor = $this->createMock(WorkflowExecutor::class);
        $starts = [];
        $executor->expects($this->exactly(2))->method('start')->willReturnCallback(function () use (&$starts) {
            $run = $this->getMockBuilder(WorkflowRun::class)->onlyMethods(['update'])->getMock();
            $run->forceFill(['id' => count($starts) + 1]);
            $run->method('update')->willReturnCallback(function ($data) use (&$starts) {
                $starts[] = data_get($data, 'context_data.trigger_data._workflow_start_node_id');

                return true;
            });

            return $run;
        });
        $this->app->instance(WorkflowExecutor::class, $executor);
        $this->artisan('workflows:process-scheduled')->assertSuccessful();
        $this->artisan('workflows:process-scheduled')->assertSuccessful();
        $this->assertSame(['trigger:first', 'trigger:second'], $starts);
        Bus::assertDispatchedTimes(ExecuteWorkflowJob::class, 2);
        $this->travelBack();
    }
}
