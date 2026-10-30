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
            ->assertDontSee('Группы сценариев')
            ->assertDontSee('Тип запуска')
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

    public function test_account_history_has_no_sidebar_and_keeps_legacy_folder_and_start_type_links(): void
    {
        $page = Livewire::test(ListWorkflowRuns::class)
            ->assertDontSee('workflow-folders', false)
            ->assertDontSee('Группы сценариев');

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

    public function test_status_filter_applies_without_an_apply_button_and_stays_within_the_account(): void
    {
        $page = Livewire::test(ListWorkflowRuns::class)
            ->set('tableFilters.status.values', ['failed']);

        $this->assertSame([4, 1], $page->instance()->getTableRecords()->getCollection()->modelKeys());
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

    public function test_history_links_to_the_initiating_card_and_keeps_entityless_runs_empty(): void
    {
        DB::table('accounts')->insert([
            ['id' => 11, 'user_id' => 1, 'widget' => 'workflows', 'active' => true, 'subdomain' => 'history-owner'],
            ['id' => 22, 'user_id' => 2, 'widget' => 'workflows', 'active' => true, 'subdomain' => 'another-owner'],
        ]);
        foreach ([1 => ['lead', 123], 2 => ['contact', 456], 3 => ['company', 789]] as $id => [$entity, $entityId]) {
            DB::table('workflow_runs')->where('id', $id)->update(['context_data' => json_encode([
                'trigger_data' => ['entity' => $entity, 'item' => ['id' => $entityId], 'account' => ['id' => 11]],
                'variables' => ['_definition_snapshot' => ['actions' => []]],
                'step_outputs' => ['created' => ['entity_type' => 'lead', 'entity_id' => 999]],
            ])]);
        }

        $page = Livewire::test(ListWorkflowRuns::class)
            ->assertSee('Сущность')
            ->assertSee('Сделка #123')
            ->assertSee('Контакт #456')
            ->assertSee('Компания #789')
            ->assertSee('https://history-owner.amocrm.ru/leads/detail/123', false)
            ->assertSee('https://history-owner.amocrm.ru/contacts/detail/456', false)
            ->assertSee('https://history-owner.amocrm.ru/companies/detail/789', false)
            ->assertDontSee('another-owner.amocrm.ru', false)
            ->assertDontSee('Сделка #999');

        $column = $page->instance()->getTable()->getColumn('trigger_entity');
        $column->record($page->instance()->getTableRecords()->firstWhere('id', 4));
        $this->assertNull($column->getState());
        $this->assertNull($column->getUrl());
        $this->assertTrue($column->shouldOpenUrlInNewTab());
        Http::assertNothingSent();
    }

    public function test_entity_links_use_preloaded_accounts_without_queries_per_row(): void
    {
        $owner = User::findOrFail(1);
        $owner->setRelation('accounts', collect([
            (new \App\Models\Core\Account)->forceFill(['id' => 11, 'user_id' => 1, 'widget' => 'workflows', 'active' => true, 'subdomain' => 'current']),
            (new \App\Models\Core\Account)->forceFill(['id' => 12, 'user_id' => 1, 'widget' => 'workflows', 'active' => false, 'subdomain' => 'original']),
        ]));
        DB::enableQueryLog();
        DB::flushQueryLog();

        foreach (range(1, 50) as $id) {
            $run = (new WorkflowRun)->forceFill(['user_id' => 1, 'context_data' => ['trigger_data' => [
                'entity' => 'lead', 'lead' => ['id' => $id], 'account' => ['id' => 12],
            ]]]);
            $run->setRelation('owner', $owner);
            $this->assertSame('https://original.amocrm.ru/leads/detail/'.$id, WorkflowRunResource::triggerEntity($run)['url']);
        }

        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        Http::assertNothingSent();
    }

    public function test_retained_trigger_index_is_used_without_confusing_changed_entities(): void
    {
        $run = WorkflowRun::findOrFail(1);
        $owner = User::findOrFail(1);
        $owner->setRelation('accounts', collect([
            (new \App\Models\Core\Account)->forceFill(['user_id' => 1, 'widget' => 'workflows', 'subdomain' => 'history-owner']),
        ]));
        $run->setRelation('owner', $owner);
        $run->setRelation('entityLinks', collect([
            (new \App\Models\Workflows\WorkflowRunEntity)->forceFill(['source' => 'step', 'entity_type' => 'lead', 'entity_id' => 999]),
            (new \App\Models\Workflows\WorkflowRunEntity)->forceFill(['source' => 'trigger', 'entity_type' => 'lead', 'entity_id' => 123]),
        ]));

        $this->assertSame(['label' => 'Сделка #123', 'url' => 'https://history-owner.amocrm.ru/leads/detail/123'], WorkflowRunResource::triggerEntity($run));
        $run->setRelation('entityLinks', collect());
        $this->assertNull(WorkflowRunResource::triggerEntity($run));
        Http::assertNothingSent();
    }
}
