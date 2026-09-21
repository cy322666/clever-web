<?php

namespace Tests\Unit\Workflows;

use Tests\TestCase;

class WorkflowReferencePanelTest extends TestCase
{
    public function test_builder_uses_variable_only_catalog_not_named_pipeline_status_groups(): void
    {
        $builder = file_get_contents(resource_path('views/vendor/filament-workflows/components/workflow-builder.blade.php'));
        $this->assertStringContainsString('::groupedConditionPickerOptions(false)', $builder);
        $this->assertStringNotContainsString('::groupedOptions(false)', $builder);
    }
    public function test_it_renders_reference_panel_without_duplicate_entity_groups(): void
    {
        $html = view('filament.workflow-builder.mask-reference', [
            'groups' => [
                'Сделка' => [
                    '{{lead.id}}' => 'ID сделки',
                    '{{lead.cf(1781099)}}' => 'Телефон · ID 1781099',
                ],
                'Счетчики' => [
                    '{{lead.tasks_count}}' => 'Количество задач',
                ],
            ],
            'systemIdGroups' => [
                'Воронки' => [['id' => '77', 'name' => 'Скрытая воронка']],
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
        $this->assertStringNotContainsString('Справочник ID', $html);
        $this->assertStringContainsString('lead.tasks_count', $html);
        $this->assertStringContainsString('Тип переменной', $html);
        $this->assertStringContainsString('x-model="type"', $html);
        $this->assertStringNotContainsString('x-text="item.value"', $html);
        $this->assertStringContainsString('x-text="item.label"', $html);
        $this->assertStringContainsString("x-text=\"'ID ' + item.id\"", $html);
        $this->assertStringContainsString('x-on:click="showDetails(item)"', $html);
        $this->assertStringContainsString('x-on:click="copy(active.id)"', $html);
        $this->assertStringContainsString('x-on:click="copy(active.value)"', $html);
        $this->assertStringContainsString('1781099', $html);
        $this->assertStringContainsString('<span>Переменная</span>', $html);
        $this->assertStringNotContainsString('Скрытая воронка', $html);
        $this->assertStringContainsString("'Показать ID и переменную: ' + item.label", $html);

        $this->assertStringNotContainsString('Поля amoCRM', $html);
        $this->assertStringNotContainsString('Воронки amoCRM', $html);
        $this->assertSame(1, substr_count($html, '{{lead.id}}'));
    }

    public function test_custom_fields_show_ids_and_keep_distinct_copy_expressions(): void
    {
        $html = view('filament.workflow-builder.mask-reference', ['groups'=>[
            'Сделка'=>[
                '{{lead.id}}'=>'ID сделки',
                '{{lead.cf(1781099)}}'=>'Телефон · ID 1781099',
                '{{lead.cf(1781100)}}'=>'Телефон · ID 1781100',
                '{{lead.cf(1781101)}}'=>'Контроль · ID 42 · ID 1781101',
            ],
            'Контакт'=>['{{contact.cf(123)}}'=>'Email · ID 123'],
        ], 'systemIdGroups'=>[]])->render();
        foreach ([1781099, 1781100, 1781101, 123] as $id) $this->assertStringContainsString((string) $id, $html);
        foreach (['{{lead.cf(1781099)}}', '{{lead.cf(1781100)}}', '{{lead.cf(1781101)}}', '{{contact.cf(123)}}', '{{lead.id}}'] as $expression) {
            $this->assertSame(1, substr_count($html, $expression));
        }
        $this->assertStringContainsString('ID 42', $html); // Part of the actual name is not removed.
        $this->assertStringContainsString('Email', $html);
        $this->assertStringContainsString('<code x-text="active?.id"></code>', $html);
        $this->assertStringContainsString('<code x-text="active?.value"></code>', $html);
        $this->assertStringNotContainsString('workflow-variable-browser__pages', $html);
    }
}
