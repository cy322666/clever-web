<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\WorkflowHistory;
use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource;
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
                'group_name' => $id === 2 ? 'Продажи' : null,
                'is_active' => false, 'definition' => ['trigger' => ['type' => $id === 2 ? 'schedule' : 'manual'], 'actions' => []]]);
            $flow->save();
        }
        foreach ([[1, 1, 1, 'failed'], [2, 1, 1, 'completed'], [3, 1, 1, 'running'], [4, 1, 2, 'failed'], [5, 2, 3, 'failed']] as [$id, $owner, $flow, $status]) {
            DB::table('workflow_runs')->insert(['id' => $id, 'user_id' => $owner, 'workflow_id' => $flow,
                'status' => $status, 'created_at' => now()->subMinutes(200 - $id),
                'context_data' => json_encode(['variables' => ['_definition_snapshot' => ['actions' => []]]])]);
        }
    }

    public function test_filter_is_applied_before_the_history_limit_and_keeps_owner_and_workflow_scope(): void
    {
        for ($id = 6; $id <= 125; $id++) {
            DB::table('workflow_runs')->insert(['id' => $id, 'user_id' => 1, 'workflow_id' => 1,
                'status' => 'completed', 'created_at' => now()->subMinutes(200 - $id)]);
        }
        $accountHistory = Livewire::test(ListWorkflowRuns::class);
        $this->assertSame(124, $accountHistory->instance()->getTableRecords()->total());
        $accountHistory->set('historyErrorsOnly', true);
        $this->assertSame(2, $accountHistory->instance()->getTableRecords()->total());
        $filtered = Livewire::withQueryParams(['workflow_id' => 1, 'errors' => 1])->test(ListWorkflowRuns::class);
        $this->assertSame([1], $filtered->instance()->getTableRecords()->pluck('id')->all());

        Http::assertNothingSent();
    }

    public function test_legacy_workflow_history_opens_the_shared_list_with_its_filters(): void
    {
        Livewire::withQueryParams(['errors' => 1])->test(WorkflowHistory::class, ['record' => 1])
            ->assertRedirect(WorkflowRunResource::getUrl('index', ['workflow_id' => 1, 'errors' => 1]));
    }

    public function test_live_filter_has_an_empty_state_and_can_be_cleared(): void
    {
        $page = Livewire::test(ListWorkflowRuns::class)->set('historyErrorsOnly', true);
        $this->assertSame(2, $page->instance()->getTableRecords()->total());
        DB::table('workflow_runs')->where('user_id', 1)->where('status', 'failed')->update(['status' => 'completed']);
        $page->call('$refresh');
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        $page->set('historyErrorsOnly', false)->assertHasNoErrors();
        $this->assertSame(4, $page->instance()->getTableRecords()->total());
        Http::assertNothingSent();
    }

    public function test_legacy_run_links_open_the_debugger_directly(): void
    {
        $url = WorkflowRunResource::debugUrl(WorkflowRun::findOrFail(1));
        Livewire::withQueryParams(['run' => 1])->test(WorkflowHistory::class, ['record' => 1])->assertRedirect($url);
        Livewire::withQueryParams(['run' => 1])->test(ListWorkflowRuns::class)->assertRedirect($url);
    }

    public function test_account_history_lists_runs_before_opening_any_details(): void
    {
        Livewire::test(ListWorkflowRuns::class)
            ->assertStatus(200)
            ->assertSee('Группы сценариев')
            ->assertSee('Тип запуска')
            ->assertSee('Поток 1')
            ->assertSee('Поток 2')
            ->assertDontSee('Поток 3')
            ->assertSee('Длительность')
            ->assertDontSee('ID запуска')
            ->assertSee('/history/1/editor', false)
            ->assertDontSee('workflow-execution-viewer', false)
            ->set('historyRunId', 1)
            ->assertRedirect(WorkflowRunResource::debugUrl(WorkflowRun::findOrFail(1)));
    }

    public function test_account_history_keeps_the_workflow_sidebar_and_filters_runs_by_folder_or_start_type(): void
    {
        $page = Livewire::test(ListWorkflowRuns::class)
            ->assertSee('workflow-folders', false)
            ->assertSee('Продажи')
            ->assertSee('Ручной запуск')
            ->assertSee('Расписание');

        $this->assertSame(4, $page->instance()->getTableRecords()->total());
        $page->call('selectFolder', 'Продажи')
            ->assertSet('workflowGroupFilter', 'Продажи')
            ->assertSet('workflowLaunchFilter', null)
            ->assertSee('folder=', false);
        $this->assertSame([4], $page->instance()->getTableRecords()->pluck('id')->all());
        $this->assertStringEndsWith('/history/4/editor', $page->instance()->getHistoryRunUrl(WorkflowRun::findOrFail(4)));
        $this->assertStringContainsString('folder=', $page->instance()->getHistoryIndexUrl());

        $page->call('selectLaunchType', 'schedule')->assertSet('workflowLaunchFilter', 'schedule');
        $this->assertSame([4], $page->instance()->getTableRecords()->pluck('id')->all());

        $page->call('selectLaunchType', 'manual')
            ->assertSet('workflowGroupFilter', null)
            ->assertSet('workflowLaunchFilter', 'manual');
        $this->assertSame(3, $page->instance()->getTableRecords()->total());
        $this->assertStringEndsWith('/history/1/editor', $page->instance()->getHistoryRunUrl(WorkflowRun::findOrFail(1)));

        $page->set('workflowLaunchFilter', 'unknown');
        $this->assertSame(0, $page->instance()->getTableRecords()->total());

        $page->call('selectFolder', null)->assertSet('workflowLaunchFilter', null);
        $this->assertSame(4, $page->instance()->getTableRecords()->total());
        $page->callAction('createFolder', ['name' => 'Новая папка'])
            ->assertHasNoActionErrors()
            ->assertSet('workflowGroupFilter', 'Новая папка');
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        Http::assertNothingSent();
    }

    public function test_account_history_cannot_open_another_clients_run(): void
    {
        $page = new ListWorkflowRuns;
        $this->assertNull($page->getSelectedRun());
        $page->historyRunId = 1;
        $this->assertSame(1, $page->getSelectedRun()?->getKey());

        $page->historyRunId = 5;
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $page->getSelectedRun();
    }

    public function test_legacy_link_cannot_open_a_run_from_another_workflow(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::withQueryParams(['run' => 4])->test(WorkflowHistory::class, ['record' => 1]);
    }

    public function test_runs_without_a_saved_schema_stay_in_the_shared_list(): void
    {
        DB::table('workflow_runs')->where('id', 1)->update(['context_data' => null]);
        $this->assertNull(WorkflowRunResource::debugUrl(WorkflowRun::findOrFail(1)));
        Livewire::withQueryParams(['run' => 1])->test(WorkflowHistory::class, ['record' => 1])
            ->assertRedirect(WorkflowRunResource::getUrl('index', ['workflow_id' => 1]));
        Livewire::withQueryParams(['run' => 1])->test(ListWorkflowRuns::class)
            ->assertRedirect(WorkflowRunResource::getUrl('index'));
    }

    public function test_account_history_does_not_show_false_millisecond_precision(): void
    {
        $started = now()->startOfSecond();
        $run = (new WorkflowRun)->forceFill([
            'status' => 'completed',
            'started_at' => $started,
            'completed_at' => $started->copy()->addMilliseconds(61),
        ]);

        $this->assertSame('менее 1 с', WorkflowRunResource::runDuration($run));
        $run->completed_at = $started->copy()->addSeconds(2);
        $this->assertSame('2 с', WorkflowRunResource::runDuration($run));
    }
}
