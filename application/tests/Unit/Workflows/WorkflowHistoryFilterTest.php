<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\WorkflowHistory;
use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource\Pages\ListWorkflowRuns;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowHistoryFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Http::preventStrayRequests();
        Schema::table('workflow_runs', function ($t) {
            $t->string('ulid')->nullable(); $t->json('context_data')->nullable();
            $t->timestamp('started_at')->nullable(); $t->integer('triggered_by')->nullable();
        });
        (require database_path('migrations/2026_06_09_160734_create_workflow_run_steps_table.php'))->up();
        $this->actingAs(User::findOrFail(1));
        foreach ([[1, 1], [2, 1], [3, 2]] as [$id, $owner]) {
            $flow = (new Workflow)->forceFill(['id' => $id, 'user_id' => $owner, 'name' => 'Поток '.$id,
                'is_active' => false, 'definition' => ['trigger' => ['type' => 'manual'], 'actions' => []]]);
            $flow->save();
        }
        foreach ([[1, 1, 1, 'failed'], [2, 1, 1, 'completed'], [3, 1, 1, 'running'], [4, 1, 2, 'failed'], [5, 2, 3, 'failed']] as [$id, $owner, $flow, $status]) {
            DB::table('workflow_runs')->insert(['id' => $id, 'user_id' => $owner, 'workflow_id' => $flow,
                'status' => $status, 'created_at' => now()->subMinutes(200 - $id)]);
        }
    }

    public function test_filter_is_applied_before_the_history_limit_and_keeps_owner_and_workflow_scope(): void
    {
        for ($id = 6; $id <= 125; $id++) {
            DB::table('workflow_runs')->insert(['id' => $id, 'user_id' => 1, 'workflow_id' => 1,
                'status' => 'completed', 'created_at' => now()->subMinutes(200 - $id)]);
        }
        $page = new WorkflowHistory;
        $page->record = Workflow::findOrFail(1);
        $this->assertCount(75, $page->getWorkflowRuns());
        $page->historyErrorsOnly = true;
        $this->assertSame([1], $page->getWorkflowRuns()->modelKeys());
        $this->assertStringContainsString('errors=1', $page->getHistoryRunUrl(WorkflowRun::findOrFail(1)));

        $all = new ListWorkflowRuns;
        $this->assertCount(100, $all->getWorkflowRuns());
        $all->historyErrorsOnly = true;
        $this->assertSame([4, 1], $all->getWorkflowRuns()->modelKeys());
        $this->assertStringContainsString('errors=1', $all->getHistoryRunUrl(WorkflowRun::findOrFail(1)));
        Http::assertNothingSent();
    }

    public function test_filter_resets_selection_and_never_leaves_a_successful_run_open(): void
    {
        foreach ([new WorkflowHistory, new ListWorkflowRuns] as $page) {
            if ($page instanceof WorkflowHistory) $page->record = Workflow::findOrFail(1);
            $page->historyRunId = 2;
            $page->historyErrorsOnly = true;
            $page->updatedHistoryErrorsOnly();
            $this->assertNull($page->historyRunId);
            $this->assertSame('failed', $page->getSelectedRun($page->getWorkflowRuns())->status->value);
            $page->historyRunId = 2; // An old link or stale URL cannot select a filtered-out execution.
            $this->assertSame('failed', $page->getSelectedRun($page->getWorkflowRuns())->status->value);
        }
    }

    public function test_live_filter_has_an_empty_state_and_can_be_cleared(): void
    {
        $page = Livewire::test(WorkflowHistory::class, ['record' => 1])->assertSee('С ошибками')
            ->set('historyRunId', 2)->set('historyErrorsOnly', true)->assertSet('historyRunId', null)
            ->assertSee('aria-pressed="true"', false)->assertSee('errors=1', false);
        DB::table('workflow_runs')->where('user_id', 1)->where('workflow_id', 1)->where('status', 'failed')->update(['status' => 'completed']);
        $page->call('$refresh')->assertSee('Запусков с ошибками нет')
            ->set('historyErrorsOnly', false)->assertDontSee('Запусков с ошибками нет')->assertHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_error_filter_can_be_opened_directly_from_a_url(): void
    {
        $page = Livewire::withQueryParams(['errors' => 1, 'run' => 1])
            ->test(WorkflowHistory::class, ['record' => 1])->assertSet('historyErrorsOnly', true);
        $this->assertSame([1], $page->instance()->getWorkflowRuns()->modelKeys());
    }
}
