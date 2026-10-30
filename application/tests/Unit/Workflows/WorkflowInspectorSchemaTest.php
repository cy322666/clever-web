<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowInspectorSchema;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowBrowserDatabase;
use Tests\TestCase;

class WorkflowInspectorSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowBrowserDatabase::prepare();
        $this->actingAs(\App\Models\User::findOrFail(1));
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $draft = new \stdClass;
        $draft->rows = [];
        app()->instance('workflow.editor.reference-draft', $draft);
    }

    public function test_export_uses_existing_fields_and_conditional_dependencies(): void
    {
        $schema = app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture,
            \App\Workflows\Actions\WorkflowHttpRequestAction::workflowConfigSchema(), ['body_format'=>'json']);
        $fields = collect($schema)->keyBy('path');
        $this->assertSame('headers', $fields['headers']['kind']);
        $this->assertSame('value', $fields['url']['kind']);
        $this->assertSame('URL', $fields['url']['label']);
        $this->assertSame('body_format', $fields['body']['visible']['dependency']);
        $this->assertContains(['value'=>'json', 'result'=>true], $fields['body']['visible']['cases']);
        $this->assertContains(['value'=>'form', 'result'=>false], $fields['body']['visible']['cases']);
        $this->assertSame('repeater',$fields['form_fields']['kind']);
        $this->assertContains(['value'=>'form','result'=>true],$fields['form_fields']['visible']['cases']);
        $this->assertSame('form_fields.*.name',$fields['form_fields']['children'][0]['path']);
    }

    public function test_supported_node_schemas_are_serializable_without_executing_nodes(): void
    {
        $registry = app(ActionRegistry::class);
        foreach ($registry->getAllWithMetadata() as $item) {
            if (!($item['available'] ?? true) || in_array($item['type'], [...\App\Workflows\Actions\WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes(), 'run_workflow'], true)) continue;
            $schema = app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture,
                $registry->getConfigSchema($item['type']), $registry->getDefaultConfig($item['type']));
            $this->assertJson(json_encode($schema, JSON_THROW_ON_ERROR), $item['type']);
        }
        $schema = app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture, \App\Workflows\Triggers\ScheduleTrigger::configSchema(), \App\Workflows\Triggers\ScheduleTrigger::defaultConfig());
        $this->assertJson(json_encode($schema, JSON_THROW_ON_ERROR));
    }
}
