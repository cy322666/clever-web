<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowRunEntityIndexService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowDatabaseMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        DB::statement('PRAGMA foreign_keys = ON');
        $this->travelTo(Carbon::parse('2026-11-02 03:45:00', 'Europe/Moscow'));
        config([
            'backup.notifications.mail.to' => 'maintenance@example.test',
            'filament-workflows.execution.raw_retention_days' => 14,
            'filament-workflows.execution.run_retention_days' => 14,
            'filament-workflows.execution.maintenance_time' => '03:45',
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_nightly_cleanup_uses_configured_retention_and_prevents_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains((string) $event->command, 'workflows:db-maintenance'),
        );

        $this->assertNotNull($event);
        $this->assertStringContainsString('--raw-days=14 --run-days=14', $event->command);
        $this->assertSame('45 3 * * *', $event->expression);
        $this->assertSame('Europe/Moscow', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_cleanup_removes_only_finished_history_older_than_fourteen_days_with_its_steps_and_links(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->json('context_data')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('usage_recorded_at')->nullable();
        });
        (require database_path('migrations/2026_06_09_160734_create_workflow_run_steps_table.php'))->up();
        (require database_path('migrations/2026_06_14_000000_harden_workflow_runtime.php'))->up();
        Schema::create('workflow_run_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->foreignId('workflow_run_step_id')->nullable()->constrained('workflow_run_steps')->nullOnDelete();
        });
        $indexer = $this->mock(WorkflowRunEntityIndexService::class);
        $indexer->shouldReceive('indexRun')->andReturnNull();
        $indexer->shouldReceive('indexStep')->andReturnNull();
        foreach ([1, 2] as $owner) {
            DB::table('workflows')->insert([
                'id' => $owner, 'user_id' => $owner, 'name' => 'Тест '.$owner,
                'trigger_type' => 'manual', 'definition' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $cases = [
            1 => [15, 'completed', 1],
            2 => [20, 'failed', 1],
            3 => [14, 'completed', 1],
            4 => [13, 'completed', 1],
            5 => [20, 'running', 1],
            6 => [30, 'paused', 1],
            7 => [40, 'pending', 1],
            8 => [16, 'cancelled', 1],
            9 => [20, 'completed', 2],
        ];
        foreach ($cases as $id => [$age, $status, $owner]) {
            DB::table('workflow_runs')->insert([
                'id' => $id, 'user_id' => $owner, 'workflow_id' => $owner, 'status' => $status,
                'context_data' => json_encode(['value' => $id]),
                'created_at' => now()->subDays($age), 'updated_at' => now(),
            ]);
            DB::table('workflow_run_steps')->insert([
                'id' => $id, 'workflow_run_id' => $id, 'step_id' => 'step',
                'step_type' => 'task', 'status' => 'completed',
                'input_data' => json_encode(['input' => $id]), 'output_data' => json_encode(['output' => $id]),
                'created_at' => now()->subDays($age), 'updated_at' => now(),
            ]);
            DB::table('workflow_run_entities')->insert(['workflow_run_id' => $id, 'workflow_run_step_id' => $id]);
        }

        $this->assertSame(0, Artisan::call('workflows:db-maintenance', ['--skip-vacuum' => true]));
        $retained = [3, 4, 5, 6, 7];
        $this->assertSame($retained, DB::table('workflow_runs')->orderBy('id')->pluck('id')->all());
        $this->assertSame($retained, DB::table('workflow_run_steps')->orderBy('id')->pluck('id')->all());
        $this->assertSame($retained, DB::table('workflow_run_entities')->orderBy('workflow_run_id')->pluck('workflow_run_id')->all());
        foreach ($retained as $id) {
            $run = DB::table('workflow_runs')->find($id);
            $step = DB::table('workflow_run_steps')->find($id);
            $this->assertSame(['value' => $id], json_decode($run->context_data, true));
            $this->assertSame(['input' => $id], json_decode($step->input_data, true));
            $this->assertSame(['output' => $id], json_decode($step->output_data, true));
            $this->assertNull($run->usage_recorded_at);
        }
        $this->assertSame(4, (int) DB::table('workflow_usage_months')->sum('runs_count'));
        $this->assertSame(4, (int) DB::table('workflow_usage_months')->sum('steps_count'));
        $this->assertSame(0, Artisan::call('workflows:db-maintenance', ['--skip-vacuum' => true]));
        $this->assertSame(4, (int) DB::table('workflow_usage_months')->sum('runs_count'));
        Http::assertNothingSent();
    }
}
