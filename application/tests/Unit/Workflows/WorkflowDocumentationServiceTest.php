<?php

namespace Tests\Unit\Workflows;

use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowDocumentationService;
use Tests\TestCase;

class WorkflowDocumentationServiceTest extends TestCase
{
    public function test_it_builds_readable_documentation_for_workflow_steps(): void
    {
        $workflow = new Workflow();
        $workflow->forceFill([
            'id' => 1001,
            'user_id' => 42,
            'name' => 'Тестовый сценарий',
            'is_active' => true,
            'definition' => [
                'version' => 2,
                'trigger' => [
                    'type' => 'manual',
                    'config' => [],
                ],
                'actions' => [
                    [
                        'id' => 'step_1',
                        'type' => 'amocrm_add_note',
                        'componentType' => 'task',
                        'config' => [
                            'target_entity' => 'lead',
                            'target_entity_id' => '{{lead.id}}',
                            'text' => 'Проверочная заметка',
                            'delay' => ['mode' => 'immediate'],
                        ],
                    ],
                    [
                        'id' => 'step_2',
                        'type' => 'control-condition',
                        'componentType' => 'control-condition',
                        'name' => 'Проверка источника',
                        'config' => [
                            'logic' => 'and',
                            'conditions' => [
                                [
                                    'left' => '{{source}}',
                                    'operator' => 'equals',
                                    'right' => 'manual',
                                ],
                            ],
                            'true_actions' => [],
                            'false_actions' => [],
                        ],
                    ],
                ],
            ],
        ]);

        $document = app(WorkflowDocumentationService::class)->singleDocument($workflow);
        $workflowDocument = $document['workflows'][0];

        $this->assertSame('Тестовый сценарий', $workflowDocument['name']);
        $this->assertSame('Ручной запуск', $workflowDocument['trigger']['name']);
        $this->assertCount(2, $workflowDocument['steps']);
        $this->assertSame('Добавить примечание', $workflowDocument['steps'][0]['name']);
        $this->assertSame('Проверка источника', $workflowDocument['steps'][1]['name']);
        $this->assertContains('{{lead.id}}', $workflowDocument['variables']);
    }
}
