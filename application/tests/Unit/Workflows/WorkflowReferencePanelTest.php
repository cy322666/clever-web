<?php

namespace Tests\Unit\Workflows;

use App\Forms\Components\WorkflowMaskTextarea;
use App\Services\Workflows\WorkflowVariableService;
use App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog;
use App\Workflows\Context\WorkflowContext;
use Tests\TestCase;

class WorkflowReferencePanelTest extends TestCase
{
    public function test_builder_uses_variable_only_catalog_not_named_pipeline_status_groups(): void
    {
        $builder = file_get_contents(resource_path('views/vendor/filament-workflows/components/workflow-builder.blade.php'));
        $this->assertStringContainsString('::groupedConditionPickerOptions(false)', $builder);
        $this->assertStringNotContainsString('::groupedOptions(false)', $builder);
    }

    public function test_removed_reference_types_are_absent_from_catalog_and_autocomplete_data(): void
    {
        $removedGroups = ['Вебхук', 'Текущая сущность', 'Беседа / чат'];
        foreach ([
            WorkflowTriggerConditionVariableCatalog::groupedMaskOptions(),
            WorkflowTriggerConditionVariableCatalog::groupedOptions(),
            WorkflowTriggerConditionVariableCatalog::groupedConditionPickerOptions(),
            WorkflowMaskTextarea::make('message')->getGroupedVariables(),
        ] as $groups) {
            foreach ($removedGroups as $group) $this->assertArrayNotHasKey($group, $groups);
            foreach (['Событие', 'Дата / время', 'Сделка', 'Контакт'] as $group) $this->assertArrayHasKey($group, $groups);
        }

        $variables = app(WorkflowVariableService::class)->getAvailableVariables();
        foreach ($removedGroups as $group) $this->assertNotContains($group, array_column($variables, 'category'));
        foreach (['payload', 'payload.key', 'raw_body', 'item.id', 'item.name', 'talk.id', 'chat_template_review.id'] as $path) {
            $this->assertNotContains($path, array_column($variables, 'path'));
            $this->assertArrayNotHasKey('{{'.$path.'}}', WorkflowTriggerConditionVariableCatalog::flatMaskOptions());
        }
        $this->assertArrayHasKey('{{lead.id}}', WorkflowTriggerConditionVariableCatalog::searchMasks('ID сделки'));
        $this->assertSame([], WorkflowTriggerConditionVariableCatalog::searchMasks('Тело запроса'));
    }

    public function test_reference_html_does_not_send_removed_types_or_webhook_modifier_to_the_browser(): void
    {
        $html = view('filament.workflow-builder.mask-reference', [
            'groups' => WorkflowTriggerConditionVariableCatalog::groupedMaskOptions(),
            'systemIdGroups' => [],
        ])->render();

        foreach (['Вебхук', 'Текущая сущность', 'Беседа / чат', 'Тело webhook', 'payload', 'talk.', 'chat_template_review.'] as $removed) {
            $this->assertStringNotContainsString($removed, html_entity_decode($html));
        }
        foreach (['Модификаторы', '{{now:date(d.m.Y)}}', '{{lead.name:trim}}', '{{lead.id}}', '{{contact.id}}'] as $retained) {
            $this->assertStringContainsString($retained, $html);
        }
    }

    public function test_reference_cleanup_does_not_remove_existing_runtime_values(): void
    {
        $context = new WorkflowContext([
            'payload' => ['order_id' => 5074],
            'item' => ['id' => 42],
            'talk' => ['id' => 17],
        ]);
        $this->assertSame('5074', $context->resolve('{{payload.order_id}}'));
        $this->assertSame('42', $context->resolve('{{item.id}}'));
        $this->assertSame('17', $context->resolve('{{talk.id}}'));
        $this->assertSame('{"order_id":5074}', $context->resolve('{{payload:json}}'));
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
        $this->assertStringContainsString('aria-label="Копировать ID" x-on:click="copy(active.id)"', $html);
        $this->assertStringContainsString('aria-label="Копировать переменную" x-on:click="copy(active.value)"', $html);
        $this->assertStringNotContainsString('workflow-variable-browser__pages', $html);
    }
}
