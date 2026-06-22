<?php

namespace Tests\Unit\Workflows;

use Tests\TestCase;

class WorkflowReferencePanelTest extends TestCase
{
    public function test_it_renders_reference_panel_without_duplicate_entity_groups(): void
    {
        $html = view('filament.workflow-builder.mask-reference', [
            'groups' => [
                'Сделка' => [
                    '{{lead.id}}' => 'ID сделки',
                ],
                'Поля сделки' => [
                    '{{lead.cf(1781099)}}' => 'Телефон · ID 1781099',
                ],
                'Счетчики' => [
                    '{{lead.tasks_count}}' => 'Количество задач',
                ],
            ],
            'systemIdGroups' => [
                'Поля' => [
                    [
                        'id' => '1781099',
                        'name' => 'Телефон',
                        'subtitle' => 'Сделка',
                        'entity' => 'Сделка',
                        'options' => [],
                    ],
                ],
                'Сделка' => [
                    [
                        'id' => '{{lead.id}}',
                        'name' => 'ID сделки',
                        'subtitle' => 'Переменная',
                        'kind' => 'variable',
                        'options' => [],
                    ],
                ],
            ],
        ])->render();

        $this->assertStringContainsString('Модификаторы', $html);
        $this->assertStringContainsString('Справочник ID', $html);
        $this->assertStringContainsString('lead.tasks_count', $html);
        $this->assertStringContainsString('selectedSystemGroup === \'Поля\'', $html);
        $this->assertStringContainsString('Переменная:', $html);

        $this->assertStringNotContainsString('Поля amoCRM', $html);
        $this->assertStringNotContainsString('Воронки amoCRM', $html);
        $this->assertStringNotContainsString('Телефон · ID 1781099', $html);
        $this->assertStringNotContainsString('"group":"Поля сделки"', $html);
    }
}
