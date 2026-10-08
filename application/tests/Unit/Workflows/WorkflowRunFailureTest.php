<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowRunFailure;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;
use Tests\Support\WorkflowHistoryFixture;
use Tests\TestCase;

class WorkflowRunFailureTest extends TestCase
{
    public function test_run_level_failure_is_visible_even_without_steps(): void
    {
        $run = WorkflowHistoryFixture::sampleRun();
        $run->status = 'failed';
        $run->error_message = 'Не указана воронка сделки.';
        $run->setRelation('steps', collect());
        $html = view('filament.workflow-builder.workflow-run-failure', compact('run'))->render();
        $this->assertStringContainsString('Не указана воронка сделки.', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Запуск #9', $html);
        $this->assertStringNotContainsString('действия не отменяются', $html);
        $history = file_get_contents(resource_path('views/filament/workflow-builder/workflow-history-page.blade.php'));
        $this->assertStringContainsString("workflow-run-failure', ['run' => \$selectedRun]", $history);
    }

    public function test_step_failure_is_used_when_run_message_is_missing(): void
    {
        $run = WorkflowHistoryFixture::sampleRun();
        $run->status = 'failed';
        $run->setRelation('steps', collect([(new WorkflowRunStep)->forceFill(['id' => 1, 'status' => 'failed', 'error_message' => 'HTTP 403: нет доступа'])]));
        $this->assertSame('HTTP 403: нет доступа', WorkflowRunFailure::message($run));
        $html = view('filament.workflow-builder.workflow-run-failure', compact('run'))->render();
        $this->assertStringContainsString('Выполненные действия не отменяются', $html);
    }

    public function test_errors_are_escaped_and_credentials_redacted(): void
    {
        $run = WorkflowHistoryFixture::sampleRun();
        $run->status = 'failed';
        $run->context_data = ['token' => 'private-test-credential'];
        $run->error_message = '<script>alert(1)</script> private-test-credential Bearer another-private-value';
        $html = view('filament.workflow-builder.workflow-run-failure', compact('run'))->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('private-test-credential', $html);
        $this->assertStringNotContainsString('another-private-value', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('[REDACTED]', $html);
    }

    public function test_missing_error_is_honest_and_completed_runs_have_no_banner(): void
    {
        $run = WorkflowHistoryFixture::sampleRun();
        $this->assertNull(WorkflowRunFailure::message($run));
        $run->status = 'failed';
        $this->assertStringContainsString('не сохранилась', WorkflowRunFailure::message($run));
    }
}
