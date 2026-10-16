<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowEditorReferenceQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\WorkflowBrowserDatabase;
use Tests\TestCase;

class WorkflowEditorDraftPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowBrowserDatabase::prepare();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::findOrFail(2));
    }

    private function workflow(): Workflow
    {
        return Workflow::create(['name' => 'Исходный', 'user_id' => 2, 'is_active' => false, 'definition' => [
            'trigger' => ['type' => 'manual', 'config' => []],
            'actions' => [['id' => 'a', 'name' => 'Пауза', 'type' => 'workflow_delay', 'config' => ['seconds' => 1]]],
            'vendor_key' => ['keep' => true],
        ]]);
    }

    private function queries(string $table): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn ($entry) => str_contains($entry['query'], '"'.$table.'"')));
    }

    public function test_identity_and_graph_changes_do_not_read_or_write_workflow_until_save(): void
    {
        $flow = $this->workflow();
        $before = $flow->fresh()->getRawOriginal();
        $page = Livewire::test(DraftPersistenceEditor::class, ['record' => $flow->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page->call('renameWorkflow', 'Черновик')->call('setWorkflowTags', ['тег'])
            ->call('renameWorkflowNode', 'a', 'Ожидание', true)
            ->call('selectActionType', 'workflow_delay')->assertHasNoErrors();
        $this->assertSame([], $this->queries('workflows'), 'Ordinary editor requests must not restore or autosave an Eloquent workflow.');
        $this->assertSame($before, $flow->fresh()->getRawOriginal());
        $page->call('save')->assertHasNoErrors();
        $saved = $flow->fresh();
        $this->assertSame('Черновик', $saved->name);
        $this->assertSame(['тег'], $saved->definition['tags']);
        $this->assertSame('Ожидание', $saved->definition['actions'][0]['name']);
        $this->assertCount(2, $saved->definition['actions']);
        $this->assertSame(['keep' => true], $saved->definition['vendor_key']);
        $page->call('save')->assertHasNoErrors(); // baseline must advance
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_dictionary_is_reused_across_requests_and_refreshed_only_explicitly(): void
    {
        DB::table('amocrm_staffs')->insert([
            ['user_id' => 2, 'staff_id' => 22, 'name' => 'Свой'],
            ['user_id' => 1, 'staff_id' => 11, 'name' => 'Чужой'],
        ]);
        $page = Livewire::test(DraftPersistenceEditor::class, ['record' => $this->workflow()->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page->call('readDictionary')->assertSet('dictionary', ['Свой']);
        $this->assertCount(1, $this->queries('amocrm_staffs'), json_encode($this->queries('amocrm_staffs')));
        DB::table('amocrm_staffs')->where('staff_id', 22)->update(['name' => 'Обновлён']);
        DB::flushQueryLog();
        $page->call('readDictionary')->assertSet('dictionary', ['Свой']);
        $this->assertSame([], $this->queries('amocrm_staffs'));
        $page->call('refreshEditingNodeReferences')->call('readDictionary')->assertSet('dictionary', ['Обновлён']);
        $this->assertCount(1, array_filter($this->queries('amocrm_staffs'), fn ($query) => str_starts_with($query['query'], 'select "name"')));
        DB::flushQueryLog();
        $page->call('readDictionary')->assertSet('dictionary', ['Обновлён']);
        $this->assertSame([], $this->queries('amocrm_staffs'));
    }

    public function test_save_rechecks_current_owner_instead_of_trusting_the_signed_snapshot(): void
    {
        $flow = $this->workflow();
        $page = Livewire::test(DraftPersistenceEditor::class, ['record' => $flow->id]);
        DB::table('workflows')->where('id', $flow->id)->update(['user_id' => 1]);
        try {
            $page->set('data.name', 'Нельзя записать')->call('save');
            $this->fail('A transferred workflow must not be writable by its old owner.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame('Исходный', DB::table('workflows')->where('id', $flow->id)->value('name'));
        }
    }

    public function test_concurrent_saved_changes_are_not_overwritten_and_local_draft_is_retained(): void
    {
        $flow = $this->workflow();
        $page = Livewire::test(DraftPersistenceEditor::class, ['record' => $flow->id]);
        $flow->update(['name' => 'Из другого окна']);
        $page->set('data.name', 'Мой черновик')->call('save')->assertHasErrors('definition')
            ->assertSet('data.name', 'Мой черновик');
        $this->assertSame('Из другого окна', $flow->fresh()->name);
    }

    public function test_record_snapshot_cannot_be_replaced_by_the_browser(): void
    {
        $page = Livewire::test(DraftPersistenceEditor::class, ['record' => $this->workflow()->id]);
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $page->set('workflowRecordSnapshot.user_id', 1);
    }
}

class DraftPersistenceEditor extends EditWorkflow
{
    public array $dictionary = [];

    protected function authorizeAccess(): void {}

    public function readDictionary(): void
    {
        $this->dictionary = WorkflowEditorReferenceQuery::for(\App\Models\amoCRM\Staff::class)
            ->where('user_id', auth()->id())->where('active', true)->orderBy('name')->get(['name'])->pluck('name')->all();
    }

    public function refreshEditingNodeReferences(): void {} // existing lifecycle invalidates the cache

    public function render(): \Illuminate\Contracts\View\View
    {
        return view()->file(__DIR__.'/../../Support/workflow-save-state.blade.php');
    }
}
