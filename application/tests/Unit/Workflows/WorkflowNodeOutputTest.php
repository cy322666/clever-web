<?php

namespace Tests\Unit\Workflows;

use Tests\TestCase;

class WorkflowNodeOutputTest extends TestCase
{
    private function renderResult(array $result): string
    {
        $page = new class($result) {
            public function __construct(private array $result) {}

            public function editingNodeResult(): array
            {
                return $this->result;
            }
        };

        return view('filament.workflow-builder.workflow-node-output', [
            'getLivewire' => fn () => $page,
        ])->render();
    }

    private function viewerKey(string $html): string
    {
        $this->assertSame(1, preg_match('/wire:key="(workflow-node-output-[a-f0-9]+)"/', $html, $matches));

        return $matches[1];
    }

    public function test_changed_output_remounts_the_alpine_json_viewer(): void
    {
        $failed = ['id' => 'request', 'output' => ['status' => false, 'error' => 'Old unauthorized response']];
        $success = ['id' => 'request', 'output' => ['status' => true, 'result' => [['id' => 42]]]];

        $this->assertNotSame(
            $this->viewerKey($this->renderResult($failed)),
            $this->viewerKey($this->renderResult($success)),
        );
    }

    public function test_timeout_removes_the_previous_response(): void
    {
        $previous = ['id' => 'request', 'output' => ['error' => 'Old unauthorized response']];
        $timeout = ['id' => 'request', 'error' => 'Connection timed out', 'output' => []];
        $html = $this->renderResult($timeout);

        $this->assertNotSame($this->viewerKey($this->renderResult($previous)), $this->viewerKey($html));
        $this->assertStringContainsString('Connection timed out', $html);
        $this->assertStringNotContainsString('Old unauthorized response', $html);
    }

    public function test_unchanged_result_preserves_the_viewer_state(): void
    {
        $result = ['id' => 'request', 'output' => ['items' => [['id' => 42]]]];

        $this->assertSame($this->viewerKey($this->renderResult($result)), $this->viewerKey($this->renderResult($result)));
    }

    public function test_error_changes_also_reset_the_result_viewer(): void
    {
        $result = ['id' => 'request', 'output' => []];

        $this->assertNotSame(
            $this->viewerKey($this->renderResult($result)),
            $this->viewerKey($this->renderResult($result + ['error' => 'Connection timed out'])),
        );
    }
}
