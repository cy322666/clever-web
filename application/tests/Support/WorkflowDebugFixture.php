<?php

namespace Tests\Support;

class WorkflowDebugFixture extends WorkflowCanvasFixture
{
    public function mount(): void
    {
        $this->workflowActions = self::sampleDefinition()['actions'];
    }

    public static function sampleDefinition(): array
    {
        return ['trigger' => ['type' => 'manual', 'config' => []], 'actions' => [
            ['id' => 'fetch', 'type' => 'amocrm_query_leads', 'name' => 'Запрос сделок', 'config' => ['limit' => 50, 'page' => 1, 'filters' => []]],
            ['id' => 'if', 'type' => 'control-condition', 'config' => [
                'logic' => 'and', 'conditions' => [['left' => '{{ $node["fetch"].json.count }}', 'operator' => 'gt', 'right' => '0']],
                'has_true_branch' => true, 'has_false_branch' => true,
                'true_actions' => [['id' => 'yes', 'type' => 'amocrm_add_note', 'name' => 'Сделки найдены', 'config' => ['text' => 'Найдено: {{ $node["fetch"].json.count }}']]],
                'false_actions' => [['id' => 'no', 'type' => 'amocrm_add_note', 'name' => 'Сделок нет', 'config' => ['text' => 'Нет сделок']]],
            ]],
        ]];
    }
}
