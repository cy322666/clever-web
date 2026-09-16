<?php

namespace Tests\Unit\Workflows;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class WorkflowIconColorsTest extends TestCase
{
    public function test_logic_icons_use_semantic_stroke_colors_without_filling_the_glyph(): void
    {
        foreach (['workflow_javascript'=>'workflow-code-icon', 'control-condition'=>'workflow-control-icon', 'workflow_delay'=>'workflow-control-icon'] as $type=>$class) {
            $html = Blade::render('<x-workflow-icon icon="heroicon-o-code-bracket" :type="$type"/>', ['type'=>$type]);
            $this->assertStringContainsString($class, $html);
            $this->assertStringContainsString('fill="none"', $html);
            $this->assertStringContainsString('stroke="currentColor"', $html);
            $this->assertStringNotContainsString('workflow-amo-icon', $html);
        }
    }
}
