<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\ListWorkflows;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowFolder;
use App\Services\Workflows\WorkflowFolders;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowFoldersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        $this->actingAs(User::findOrFail(1));
    }

    public function test_empty_folders_persist_and_existing_groups_remain_available(): void
    {
        $this->workflow('Старый сценарий', 'Старая группа');
        WorkflowFolders::create('  Продажи  ');
        $this->assertSame(['Продажи' => 'Продажи', 'Старая группа' => 'Старая группа'], Workflow::groupOptions());
        $this->assertDatabaseHas('workflow_folders', ['user_id' => 1, 'name' => 'Продажи']);
        $this->assertSame(0, Workflow::where('group_name', 'Продажи')->count());
    }

    public function test_move_rename_and_remove_preserve_definition_and_activation(): void
    {
        $workflow = $this->workflow('Сценарий', null);
        $definition = $workflow->definition;
        WorkflowFolders::create('Продажи');
        WorkflowFolders::move($workflow, 'Продажи');
        WorkflowFolders::rename('Продажи', 'Отдел продаж');
        $this->assertSame('Отдел продаж', $workflow->fresh()->group_name);
        WorkflowFolders::remove('Отдел продаж');
        $fresh = $workflow->fresh();
        $this->assertNull($fresh->group_name);
        $this->assertTrue($fresh->is_active);
        $this->assertSame($definition, $fresh->definition);
        $this->assertFalse($fresh->trashed());
        $this->assertSame([], WorkflowFolders::options());
    }

    public function test_folder_changes_are_scoped_to_the_owner(): void
    {
        $own = $this->workflow('Свой', 'Продажи');
        $other = $this->workflow('Чужой', 'Продажи', 2);
        WorkflowFolder::create(['user_id' => 2, 'name' => 'Чужая папка']);
        $this->assertArrayNotHasKey('Чужая папка', WorkflowFolders::options());
        WorkflowFolders::rename('Продажи', 'Моя папка');
        $this->assertSame('Продажи', $other->fresh()->group_name);
        $this->assertSame('Моя папка', $own->fresh()->group_name);
        $this->expectException(HttpException::class);
        WorkflowFolders::move($other, 'Моя папка');
    }

    public function test_duplicate_names_are_rejected(): void
    {
        WorkflowFolders::create('Продажи');
        $this->expectException(ValidationException::class);
        WorkflowFolders::create(' продажи ');
    }

    public function test_unknown_destinations_are_rejected(): void
    {
        $workflow = $this->workflow('Сценарий');
        $this->expectException(ValidationException::class);
        WorkflowFolders::move($workflow, 'Несуществующая папка');
    }

    public function test_missing_migration_does_not_break_existing_groups(): void
    {
        $this->workflow('Сценарий', 'Старая группа');
        Schema::drop('workflow_folders');
        $this->assertSame(['Старая группа' => 'Старая группа'], WorkflowFolders::options());
        $this->expectException(ValidationException::class);
        WorkflowFolders::create('Новая');
    }

    public function test_anonymous_requests_never_receive_folder_names(): void
    {
        WorkflowFolders::create('Личная папка');
        Auth::forgetGuards();
        $this->assertSame([], WorkflowFolders::options());
    }

    public function test_list_creates_empty_folders_filters_and_moves_workflows(): void
    {
        $record = $this->workflow('Входящие заявки');
        $page = Livewire::test(ListWorkflows::class)
            ->assertStatus(200)->assertSee('Входящие заявки')
            ->assertDontSee('workflow-metrics', false)
            ->callAction('createFolder', ['name' => 'Новая папка'])
            ->assertHasNoActionErrors()
            ->assertSet('workflowGroupFilter', 'Новая папка')
            ->assertSee('Новая папка')->assertDontSee('Входящие заявки')
            ->call('selectFolder', null)
            ->callTableAction('move_workflow', $record, ['group_name' => 'Новая папка'])
            ->assertHasNoTableActionErrors()
            ->call('selectFolder', 'Новая папка')
            ->assertSee('Входящие заявки')
            ->callAction('renameFolder', ['name' => 'Переименованная'])
            ->assertHasNoActionErrors()->assertSet('workflowGroupFilter', 'Переименованная')
            ->callAction('deleteFolder')->assertSet('workflowGroupFilter', null);
        $this->assertNull($record->fresh()->group_name);
        $this->assertSame([], WorkflowFolders::options());
    }

    public function test_list_reports_duplicate_folder_names_inside_the_form(): void
    {
        WorkflowFolders::create('Продажи');
        Livewire::test(ListWorkflows::class)
            ->callAction('createFolder', ['name' => 'Продажи'])
            ->assertHasActionErrors(['name']);
    }

    public function test_list_groups_workflows_by_launch_type_and_hides_empty_groups(): void
    {
        $manual = $this->workflow('Ручной сценарий');
        $button = $this->workflow('Сценарий кнопки', null, 1, 'amo-button');
        $webhook = $this->workflow('Сценарий вебхука', null, 1, 'generic-webhook');
        $this->workflow('Чужой Digital Pipeline', null, 2, 'digital-pipeline');

        Livewire::test(ListWorkflows::class)
            ->assertStatus(200)
            ->assertSee('Ручной запуск')
            ->assertSee('Кнопки')
            ->assertSee('Webhooks')
            ->assertDontSee('Digital Pipeline')
            ->assertDontSee('Расписание')
            ->call('selectLaunchType', 'buttons')
            ->assertSet('workflowLaunchFilter', 'buttons')
            ->assertSet('workflowGroupFilter', null)
            ->assertSee($button->name)
            ->assertDontSee($manual->name)
            ->assertDontSee($webhook->name);
    }

    public function test_launch_type_filter_includes_additional_start_nodes(): void
    {
        $workflow = $this->workflow('Несколько запусков');
        $workflow->forceFill(['definition' => array_replace($workflow->definition, [
            'additional_triggers' => [[
                'id' => 'trigger:webhook',
                'type' => 'generic-webhook',
                'config' => [],
            ]],
        ])])->saveQuietly();

        Livewire::test(ListWorkflows::class)
            ->assertSee('Webhooks')
            ->call('selectLaunchType', 'webhooks')
            ->assertSee('Несколько запусков');
    }

    public function test_selecting_a_folder_or_launch_type_clears_the_other_grouping(): void
    {
        $this->workflow('Сценарий', 'Продажи', 1, 'manual');

        Livewire::test(ListWorkflows::class)
            ->call('selectLaunchType', 'manual')
            ->assertSet('workflowLaunchFilter', 'manual')
            ->call('selectFolder', 'Продажи')
            ->assertSet('workflowGroupFilter', 'Продажи')
            ->assertSet('workflowLaunchFilter', null)
            ->call('selectLaunchType', 'manual')
            ->assertSet('workflowGroupFilter', null)
            ->assertSet('workflowLaunchFilter', 'manual')
            ->call('selectFolder', null)
            ->assertSet('workflowGroupFilter', null)
            ->assertSet('workflowLaunchFilter', null);
    }

    public function test_list_shows_only_runs_waiting_for_processing_in_the_queue_badge(): void
    {
        $workflow = $this->workflow('Очередь заявок');
        $this->workflow('Без очереди');

        foreach (['pending', 'pending', 'running', 'completed', 'failed'] as $status) {
            DB::table('workflow_runs')->insert([
                'workflow_id' => $workflow->getKey(),
                'user_id' => 1,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $record = WorkflowResource::getEloquentQuery()->findOrFail($workflow->getKey());

        $this->assertSame(2, $record->queued_runs_count);

        $page = Livewire::test(ListWorkflows::class)
            ->assertStatus(200)
            ->assertSee('В очереди 2')
            ->assertDontSee('Открыть редактор');

        $this->assertSame(1, substr_count($page->html(), 'workflow-queue-inline-badge'));
        $this->assertStringNotContainsString('fi-ta-cell-queued_runs_count', $page->html());
    }

    public function test_list_explains_missing_folder_storage_without_a_server_error(): void
    {
        Schema::drop('workflow_folders');
        $page = Livewire::test(ListWorkflows::class)
            ->callAction('createFolder', ['name' => 'Продажи'])
            ->assertHasActionErrors(['name']);
        $this->assertContains('Сохранение папок станет доступно после обновления базы.', $page->instance()->getErrorBag()->all());
    }

    private function workflow(string $name, ?string $group = null, int $owner = 1, string $triggerType = 'manual'): Workflow
    {
        $record = (new Workflow)->forceFill([
            'user_id' => $owner, 'name' => $name, 'group_name' => $group,
            'is_active' => true, 'trigger_type' => in_array($triggerType, ['generic-webhook', 'digital-pipeline'], true) ? 'webhook' : 'manual',
            'definition' => ['trigger' => ['type' => $triggerType, 'config' => []], 'actions' => [
                ['id' => 'note', 'type' => 'amocrm_add_note', 'config' => ['text' => 'Тест']],
            ]],
        ]);
        $record->saveQuietly();

        return $record;
    }
}
