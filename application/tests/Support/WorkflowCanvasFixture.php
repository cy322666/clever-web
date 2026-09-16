<?php

namespace Tests\Support;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowPageActions;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasCompactWorkflowConfigurationPanels;
use App\Models\Workflows\Workflow;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Resources\WorkflowResource\Concerns\HasWorkflowBuilderActions;
use Livewire\Component;

class BaseWorkflowCanvasFixture extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use HasWorkflowBuilderActions;
}

class WorkflowCanvasFixture extends BaseWorkflowCanvasFixture
{
    use HasWorkflowPageActions;
    use HasCompactWorkflowConfigurationPanels;

    public ?array $trigger = ['type' => 'manual', 'config' => []];

    public ?string $targetPath = null;

    public array $workflowActions = [
        [
            'id' => 'condition',
            'type' => 'control-condition',
            'config' => [
                'conditions' => [['left' => '{{lead.price}}', 'operator' => 'gt', 'right' => '10000']],
                'has_true_branch' => true,
                'has_false_branch' => true,
                'true_actions' => [[
                    'id' => 'task',
                    'type' => 'amocrm_create_task',
                    'config' => ['text' => 'Связаться с клиентом'],
                ]],
                'false_actions' => [[
                    'id' => 'note',
                    'type' => 'amocrm_add_note',
                    'config' => ['text' => 'Уточнить бюджет'],
                ]],
            ],
        ],
    ];

    public function getRecord(): Workflow
    {
        return (new Workflow)->forceFill(['id' => 1, 'name' => 'Основная воронка']);
    }

    public function getTriggerMetadata(string $type, array $config = []): array
    {
        $class = app(\Leek\FilamentWorkflows\Triggers\TriggerRegistry::class)->get($type);

        return ['name' => $class::name(), 'icon' => $class::icon(), 'description' => $class::getConfiguredDescription($config)];
    }

    public function getWorkflowActionMetadata(string $type, array $config = []): array
    {
        return collect(app(ActionRegistry::class)->getAllWithMetadata())->firstWhere('type', $type) ?? [];
    }

    public function render(): string
    {
        return '<div><form class="workflow-editor-page"><x-filament-workflows::workflow-builder page-mode /></form><x-filament-actions::modals /></div>';
    }
}
