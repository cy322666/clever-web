<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\ListWorkflows;
use App\Models\User;
use App\Models\Workflows\Workflow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowLatestRunTest extends TestCase
{
    public function test_the_list_links_to_the_latest_run_and_handles_never_started_workflows(): void
    {
        WorkflowListDatabase::prepare();
        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
        $this->actingAs(User::findOrFail(1));
        foreach (['С запуском', 'Без запусков'] as $name) {
            $flow = (new Workflow)->forceFill(['name' => $name, 'user_id' => 1, 'is_active' => false, 'trigger_type' => 'manual', 'definition' => ['trigger' => ['type' => 'manual'], 'actions' => []]]);
            $flow->saveQuietly();
        }
        $id = Workflow::where('name', 'С запуском')->value('id');
        DB::table('workflow_runs')->insert([
            ['id' => 10, 'user_id' => 1, 'workflow_id' => $id, 'status' => 'completed', 'created_at' => now(), 'started_at' => now(), 'completed_at' => now()],
            ['id' => 11, 'user_id' => 1, 'workflow_id' => $id, 'status' => 'failed', 'created_at' => now(), 'started_at' => now(), 'completed_at' => now()->addSeconds(2)],
        ]);
        $page = Livewire::test(ListWorkflows::class)->assertStatus(200)->assertSee('Последний запуск')->assertSee('Не запускался');
        $page->assertSee('run=11', false)->assertDontSee('run=10', false);
        $this->assertSame(11, $page->instance()->getTableRecords()->firstWhere('id', $id)->latestRun->id);
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
        $xpath = new \DOMXPath($document);
        // Check the actual latest-run cell, not an unrelated left-aligned column.
        $runCell = '//td[.//a[contains(@href, "run=11")]]';
        $this->assertSame(1, $xpath->query($runCell.'//*[contains(@class, "fi-ta-text-description")]')->length);
        $this->assertSame(1, $xpath->query($runCell.'//*[contains(@class, "fi-ta-text-has-descriptions") and contains(@class, "fi-align-start")]')->length);
        $this->assertSame(0, $xpath->query($runCell.'[contains(@class, "fi-ta-cell-name")]')->length);
        $this->assertSame(2, $xpath->query('//td[contains(@class, "fi-ta-cell-name")]//*[contains(@class, "fi-ta-text-description")]')->length);
    }

    public function test_description_icon_indent_applies_only_to_the_workflow_name(): void
    {
        $css = file_get_contents(resource_path('css/filament-workflows.css'));
        $this->assertSame(1, preg_match('/^\.workflow-list-page \.fi-ta-text-description\s*\{([^}]+)\}/m', $css, $description));
        $this->assertStringContainsString('padding-left: 0;', $description[1]);
        $this->assertSame(1, preg_match('/^\.workflow-list-page \.fi-ta-cell-name \.fi-ta-text-description\s*\{([^}]+)\}/m', $css, $name));
        $this->assertStringContainsString('padding-left: 1.875rem;', $name[1]);
    }
}
