<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\ListWorkflows;
use App\Models\User;
use App\Models\Workflows\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowIndexFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Http::preventStrayRequests();
        $this->actingAs(User::findOrFail(1));

        foreach ([[1, 1, true], [2, 1, false], [3, 1, true], [4, 2, false]] as [$id, $owner, $active]) {
            (new Workflow)->forceFill([
                'id' => $id, 'user_id' => $owner, 'name' => 'Сценарий '.$id,
                'group_name' => $id === 2 ? 'Продажи' : null,
                'is_active' => $active, 'trigger_type' => 'manual',
                'definition' => ['trigger' => ['type' => 'manual', 'config' => []], 'actions' => []],
            ])->saveQuietly();
        }

        foreach ([[1, 1, 'failed'], [2, 1, 'completed'], [3, 2, 'failed'], [4, 4, 'failed']] as [$id, $workflow, $status]) {
            DB::table('workflow_runs')->insert([
                'id' => $id, 'workflow_id' => $workflow, 'user_id' => $workflow === 4 ? 2 : 1,
                'status' => $status, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_activity_filter_applies_without_submission_and_preserves_activation(): void
    {
        $page = Livewire::test(ListWorkflows::class)
            ->set('tableFilters.is_active.value', '0');
        $this->assertEqualsCanonicalizing([2], $page->instance()->getTableRecords()->modelKeys());

        $page->set('tableFilters.is_active.value', '1');
        $this->assertEqualsCanonicalizing([1, 3], $page->instance()->getTableRecords()->modelKeys());

        $page->call('resetTableFiltersForm');
        $this->assertEqualsCanonicalizing([1, 2, 3], $page->instance()->getTableRecords()->modelKeys());
        $this->assertSame([true, false, true, false], array_map('boolval', DB::table('workflows')->orderBy('id')->pluck('is_active')->all()));
        Http::assertNothingSent();
    }

    public function test_last_run_filter_uses_the_latest_run_and_respects_account_and_folder(): void
    {
        $page = Livewire::test(ListWorkflows::class)
            ->set('tableFilters.last_run_status.value', 'failed');
        $this->assertSame([2], $page->instance()->getTableRecords()->modelKeys());

        $page->set('tableFilters.last_run_status.value', 'completed');
        $this->assertSame([1], $page->instance()->getTableRecords()->modelKeys());

        $page->set('tableFilters.last_run_status.value', 'never');
        $this->assertSame([3], $page->instance()->getTableRecords()->modelKeys());

        $page->call('selectFolder', 'Продажи');
        $this->assertSame([], $page->instance()->getTableRecords()->modelKeys());
        $page->set('tableFilters.last_run_status.value', 'failed');
        $this->assertSame([2], $page->instance()->getTableRecords()->modelKeys());
        Http::assertNothingSent();
    }
}
