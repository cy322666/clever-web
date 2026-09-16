<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowAnalytics;
use App\Models\Workflows\Workflow;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp(); WorkflowListDatabase::prepare();
        Schema::table('workflow_runs', function(Blueprint $table) { $table->string('status')->default('completed'); $table->timestamp('started_at')->nullable(); $table->timestamp('completed_at')->nullable(); });
        $this->actingAs(\App\Models\User::findOrFail(1));
    }

    public function test_analytics_is_owner_scoped_and_excludes_old_and_future_runs(): void
    {
        $row = ['user_id' => 1, 'workflow_id' => 100, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now(), 'started_at' => now()->subSeconds(10), 'completed_at' => now()];
        DB::table('workflow_runs')->insert([$row, array_replace($row, ['status' => 'failed']), array_replace($row, ['user_id' => 2]), array_replace($row, ['created_at' => now()->subDays(20)]), array_replace($row, ['created_at' => now()->addDay()])]);
        $stats = WorkflowAnalytics::summarize(1, 14);
        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(50.0, $stats['success_rate']);
        $this->assertSame(10.0, $stats['duration']);
        $this->assertCount(14, $stats['series']);
        $this->assertSame(2, array_sum(array_column($stats['series'], 'total')));
    }

    public function test_empty_analytics_is_not_a_fake_success_rate(): void
    {
        $stats = WorkflowAnalytics::summarize(1);
        $this->assertNull($stats['success_rate']);
        $this->assertNull($stats['duration']);
        $this->assertSame(0, $stats['total']);
    }

    public function test_graph_connections_survive_model_save_and_reload(): void
    {
        $definition = WorkflowConnectionsTest::definition();
        $workflow = new Workflow;
        $workflow->forceFill(['user_id' => 1, 'name' => 'Тест связей', 'definition' => $definition, 'is_active' => false, 'failure_strategy' => 'stop'])->save();
        $this->assertSame($definition['connections'], $workflow->fresh()->definition['connections']);
        $workflow->definition = array_replace($definition, ['connections' => []]);
        $workflow->is_active = true;
        $workflow->save();
        $this->assertFalse($workflow->fresh()->is_active);
    }
}
