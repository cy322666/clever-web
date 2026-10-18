<?php

namespace Tests\Unit\Workflows;

use Tests\TestCase;

class WorkflowNodeOutputTest extends TestCase
{
    private function renderResult(array $result): string
    {
        $page = new class($result) {
            public ?string $nodePreviewTaskId = null;
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

    public function test_condition_shows_recorded_operands_types_operator_and_result_safely(): void
    {
        $html = $this->renderResult(['type' => 'control-condition', 'status' => 'completed', 'output' => [
            'passed' => false, 'branch' => 'false', 'condition_results' => [
                ['left_value' => 17, 'operator' => 'strict_equals', 'right_value' => '17', 'passed' => false],
                ['left_value' => '<script>alert(1)</script>', 'operator' => 'equals', 'right_value' => null, 'passed' => false],
                ['left_value' => false, 'operator' => 'equals', 'right_value' => 0, 'passed' => false],
            ],
        ]]);
        foreach (['Значение 1', 'Значение 2', '17', 'строка', 'число', 'строго равно', 'null', 'логическое', 'не задано', 'Условие не выполнено', '&lt;script&gt;'] as $text) $this->assertStringContainsString($text, $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<header>', $html);
        $this->assertStringContainsString('Ветка «Нет»', $html);
    }

    public function test_unary_condition_does_not_invent_a_second_operand(): void
    {
        $html = $this->renderResult(['type' => 'control-condition', 'status' => 'completed', 'duration_ms' => 2, 'output' => [
            'passed' => true, 'branch' => 'true', 'condition_results' => [
                ['left_value' => '', 'operator' => 'is_empty', 'passed' => true],
            ],
        ]]);
        $this->assertStringContainsString('пустая строка', $html);
        $this->assertStringNotContainsString('Значение 2', $html);
        $this->assertStringContainsString('Ветка «Да»', $html);
        $this->assertStringContainsString('2 мс', $html);
        $this->assertSame(1, substr_count($html, 'Условие выполнено'));
    }

    public function test_result_shows_only_duration_without_historical_disclaimer(): void
    {
        $html = $this->renderResult(['output' => ['id' => 42], 'duration_ms' => 17, 'historical' => true]);
        $this->assertStringContainsString('17 мс', $html);
        $this->assertStringNotContainsString('Результат <small>', $html);
        $this->assertStringNotContainsString('Данные сохранённого запуска', $html);
        $this->assertStringNotContainsString('После правок', $html);
    }

    public function test_saved_condition_with_only_a_decision_still_shows_its_branch(): void
    {
        $html = $this->renderResult(['type' => 'control-condition', 'status' => 'completed', 'historical' => true,
            'output' => ['passed' => false], 'duration_ms' => 1400]);
        $this->assertStringContainsString('Ветка «Нет»', $html);
        $this->assertStringContainsString('1400 мс', $html);
        $this->assertStringNotContainsString('&quot;passed&quot;', $html);
    }

    public function test_json_rows_do_not_render_template_indentation_as_preformatted_text(): void
    {
        $html = $this->renderResult(['output' => ['status' => 200, 'success' => true, 'body' => ['id' => 42]]]);
        $this->assertSame(1, preg_match('/<button\b[^>]*class="workflow-input-json__value"[^>]*>(.*?)<\/button>/s', $html, $matches));
        $this->assertSame(trim($matches[1]), $matches[1]);
        $this->assertStringNotContainsString("\n", $matches[1]);
    }
}
