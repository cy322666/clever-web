<?php

namespace Tests\Unit\Workflows;

use App\Models\Workflows\Workflow;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowExecutionControlsTest extends TestCase
{
    public function test_references_and_activation_are_left_of_run_while_save_stays_in_the_header(): void
    {
        WorkflowCanvasDatabase::prepare();
        $html = Livewire::test(WorkflowCanvasFixture::class)->assertStatus(200)->html();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $header = '//div[@class="workflow-workbench__quick-actions"]';
        $footer = '//div[@class="workflow-canvas-execution-controls"]';

        $this->assertCount(0, $xpath->query('//*[@aria-label="Теги потока"]'));
        $this->assertCount(1, $xpath->query('//summary[@aria-label="Папка потока"]'));
        $this->assertCount(0, $xpath->query($header.'//*[@data-workflow-reference-toggle or @data-workflow-activation-toggle]'));
        $this->assertCount(1, $xpath->query($header.'//button[@type="submit" and contains(@class,"workflow-save-button")]'));
        $this->assertCount(0, $xpath->query($header.'//*[@aria-label="Отладка" or @aria-label="История"]'));
        $this->assertCount(1, $xpath->query($footer.'[@role="toolbar" and @aria-label="Запуск и отладка"]'));
        $this->assertCount(1, $xpath->query($footer.'//button[@class="workflow-canvas-run"]'));
        $run = $footer.'//button[@class="workflow-canvas-run"]';
        $this->assertCount(1, $xpath->query($run.'/preceding-sibling::button[@data-workflow-reference-toggle]'));
        $this->assertCount(1, $xpath->query($run.'/preceding-sibling::button[@data-workflow-activation-toggle]'));
        $this->assertCount(0, $xpath->query($run.'/following-sibling::button[@aria-label="Отладка"]'));
        $this->assertCount(1, $xpath->query($run.'/following-sibling::a[@aria-label="История"]'));
        $debug = $xpath->query($footer.'//button[@aria-label="Отладка"]');
        $history = $xpath->query($footer.'//a[@aria-label="История"]');
        $this->assertCount(0, $debug);
        $this->assertCount(1, $history);
        $this->assertStringContainsString('openDebug()', $xpath->query($run)[0]->getAttribute('x-on:click.stop'));
        $this->assertStringEndsWith('/panel/workflow-runs?workflow_id=1', $history[0]->getAttribute('href'));
        $this->assertCount(0, $xpath->query($footer.'//*[@type="submit"]'));
        $this->assertCount(1, $xpath->query($footer.'/ancestor::*[@x-ref="viewport"]'));
        $this->assertCount(0, $xpath->query($footer.'/ancestor::*[@x-ref="stage"]'));
    }

    public function test_activation_uses_an_accessible_icon_without_visible_text_in_both_states(): void
    {
        WorkflowCanvasDatabase::prepare();
        foreach ([false, true] as $enabled) {
            $html = Livewire::test(WorkflowActivationControlsFixture::class, ['activationEnabled' => $enabled])->assertStatus(200)->html();
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
            $buttons = (new \DOMXPath($document))->query('//*[@data-workflow-activation-toggle]');
            $this->assertCount(1, $buttons);
            $button = $buttons[0];
            $this->assertSame($enabled ? 'true' : 'false', $button->getAttribute('aria-pressed'));
            $this->assertSame($enabled ? 'Выключить поток' : 'Включить поток', $button->getAttribute('aria-label'));
            $this->assertSame('', trim($button->textContent));
            $this->assertSame('toggleWorkflowActivation', $button->getAttribute('wire:click'));
            $this->assertSame('disabled', $button->getAttribute('wire:loading.attr'));
            $this->assertSame('toggleWorkflowActivation', $button->getAttribute('wire:target'));
        }
        $css = file_get_contents(resource_path('css/filament-workflows.css'));
        $this->assertMatchesRegularExpression('/\.workflow-canvas-activation\[aria-pressed="true"\] \{[^}]*color: #15803d;/', $css);
        $this->assertMatchesRegularExpression('/\.dark \.workflow-canvas-activation\[aria-pressed="true"\] \{[^}]*color: #86efac;/', $css);
    }
}

class WorkflowActivationControlsFixture extends WorkflowCanvasFixture
{
    public bool $activationEnabled = false;

    public function getRecord(): Workflow
    {
        return parent::getRecord()->forceFill(['is_active' => $this->activationEnabled]);
    }
}
