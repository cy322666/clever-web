<?php

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowCanvasAreas;
use App\Services\Workflows\WorkflowGraph;
use App\Services\Workflows\WorkflowTransfer;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowCanvasAreasTest extends TestCase
{
    private function area(): array
    {
        return ['id' => 'area_contact', 'title' => 'Контакт', 'x' => -80, 'y' => 40, 'width' => 640, 'height' => 360];
    }

    public function test_areas_survive_save_reload_export_import_and_copy_without_changing_execution(): void
    {
        WorkflowListDatabase::prepare();
        $this->actingAs(User::findOrFail(1));
        Http::preventStrayRequests();
        $definition = ['trigger' => ['type' => 'manual', 'config' => []],
            'actions' => [['id' => 'wait', 'type' => 'workflow_delay', 'config' => ['seconds' => 1]]],
            'canvas_areas' => [$this->area()]];
        $record = Workflow::create(['name' => 'Этапы', 'user_id' => 1, 'is_active' => false, 'definition' => $definition]);
        $saved = $record->fresh()->definition;
        $this->assertEquals([$this->area()], $saved['canvas_areas']);
        $copy = $record->replicate();
        $copy->save();
        $this->assertSame($saved['canvas_areas'], $copy->fresh()->definition['canvas_areas']);
        $imported = WorkflowTransfer::import(WorkflowTransfer::encode('Этапы', $saved));
        $this->assertSame($saved['canvas_areas'], $imported->fresh()->definition['canvas_areas']);
        unset($definition['canvas_areas']);
        $this->assertSame(WorkflowGraph::ordered($definition), WorkflowGraph::ordered($saved));
        Http::assertNothingSent();
    }

    public function test_normalization_bounds_geometry_and_removes_unexpected_fields(): void
    {
        $this->assertSame([], WorkflowCanvasAreas::normalize([]));
        $area = $this->area();
        $normalized = WorkflowCanvasAreas::normalize([$area + ['node_ids' => ['danger'], 'html' => '<script>']]);
        $this->assertArrayNotHasKey('html', $normalized[0]);
        $this->assertArrayNotHasKey('node_ids', $normalized[0]);
        foreach ([null, 'bad', [$area, $area], [array_replace($area, ['id' => '<script>'])],
            [array_replace($area, ['title' => str_repeat('я', 121)])], [array_replace($area, ['width' => 1])],
            [array_replace($area, ['x' => INF])], [array_replace($area, ['height' => 20001])],
            array_fill(0, 101, $area)] as $invalid) {
            try {
                WorkflowCanvasAreas::normalize($invalid);
                $this->fail('Invalid area metadata was accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
