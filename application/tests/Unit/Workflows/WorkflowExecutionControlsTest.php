<?php

namespace Tests\Unit\Workflows;

use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowExecutionControlsTest extends TestCase
{
    public function test_debug_and_history_move_next_to_run_while_save_and_references_stay_in_the_header(): void
    {
        WorkflowCanvasDatabase::prepare();
        $html = Livewire::test(WorkflowCanvasFixture::class)->assertStatus(200)->html();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $header = '//div[@class="workflow-workbench__quick-actions"]';
        $footer = '//div[@class="workflow-canvas-execution-controls"]';

        $this->assertCount(1, $xpath->query($header.'//*[@data-workflow-reference-toggle]'));
        $this->assertCount(1, $xpath->query($header.'//button[@type="submit" and contains(@class,"workflow-save-button")]'));
        $this->assertCount(0, $xpath->query($header.'//*[@aria-label="Отладка" or @aria-label="История"]'));
        $this->assertCount(1, $xpath->query($footer.'[@role="toolbar" and @aria-label="Запуск и отладка"]'));
        $this->assertCount(1, $xpath->query($footer.'//button[@class="workflow-canvas-run"]'));
        $debug = $xpath->query($footer.'//button[@aria-label="Отладка"]');
        $history = $xpath->query($footer.'//a[@aria-label="История"]');
        $this->assertCount(1, $debug);
        $this->assertCount(1, $history);
        $this->assertSame('openWorkflowDebugger', $debug[0]->getAttribute('wire:click'));
        $this->assertStringEndsWith('/panel/workflows/1/history', $history[0]->getAttribute('href'));
        $this->assertCount(0, $xpath->query($footer.'//*[@type="submit" or @data-workflow-reference-toggle]'));
        $this->assertCount(1, $xpath->query($footer.'/ancestor::*[@x-ref="viewport"]'));
        $this->assertCount(0, $xpath->query($footer.'/ancestor::*[@x-ref="stage"]'));
    }
}
