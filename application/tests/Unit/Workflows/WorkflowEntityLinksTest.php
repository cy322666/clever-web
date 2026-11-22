<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Services\Workflows\WorkflowEntityLinks;
use App\Services\Workflows\WorkflowInspectorSchema;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\Http;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Livewire\Livewire;
use Tests\Support\WorkflowBrowserDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowEntityLinksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowBrowserDatabase::prepare();
        $this->actingAs(\App\Models\User::findOrFail(1));
        Http::preventStrayRequests();
    }

    private function editor(string $type = 'amocrm_link_entity', array $config = [])
    {
        return Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [['id' => 'link', 'type' => $type, 'config' => $config]]])
            ->call('openWorkflowActionEditor', 'link');
    }

    public function test_link_options_exclude_the_source_type_and_both_secondary_fields_use_expression_controls(): void
    {
        $registry = app(ActionRegistry::class);
        $flatten = function (array $rows) use (&$flatten): array {
            return array_merge($rows, ...array_map(fn ($row) => $flatten($row['children'] ?? []), $rows));
        };
        foreach (['amocrm_link_entity', 'amocrm_unlink_entity'] as $type) {
            $schema = app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture, $registry->getConfigSchema($type), $registry->getDefaultConfig($type));
            $fields = collect($flatten($schema))->keyBy('path');
            $this->assertSame('value', $fields['linked_entity']['kind']);
            $this->assertSame('value', $fields['linked_entity_id']['kind']);
            $this->assertSame('ID сущности', $fields['linked_entity_id']['label']);
            $this->assertSame('target_entity', $fields['linked_entity']['options']['dependency']);
            foreach (['lead', 'contact', 'company', 'customer'] as $entity) {
                $options = collect($fields['linked_entity']['options']['cases'])->firstWhere('value', $entity)['result'];
                $this->assertArrayNotHasKey($entity, $options);
                $this->assertCount(3, $options);
            }
        }
        $page = $this->editor();
        $html = implode('', $page->effects['partials'] ?? []);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $buttons = (new \DOMXPath($document))->query('//button[@aria-label="Вставить поле" and not(ancestor::template)]');
        $this->assertCount(4, $buttons);
    }

    public function test_same_type_cannot_be_saved_even_when_the_ui_is_bypassed(): void
    {
        foreach (['amocrm_link_entity', 'amocrm_unlink_entity'] as $type) {
            foreach (['lead', 'contact', 'company', 'customer'] as $entity) {
                $this->editor($type)
                    ->set('mountedActions.0.data.target_entity', $entity)
                    ->set('mountedActions.0.data.target_entity_id', '42')
                    ->set('mountedActions.0.data.linked_entity', $entity)
                    ->set('mountedActions.0.data.linked_entity_id', '77')
                    ->call('callMountedAction')
                    ->assertHasErrors(['mountedActions.0.data.linked_entity']);
            }
        }
        Http::assertNothingSent();
    }

    public function test_changing_a_type_clears_only_the_stale_link_selection_and_id(): void
    {
        $page = $this->editor('amocrm_link_entity', ['entity_source' => 'manual', 'target_entity' => 'lead', 'target_entity_id' => 42, 'linked_entity' => 'contact', 'linked_entity_id' => 77]);
        $page->set('mountedActions.0.data.target_entity', 'contact')
            ->assertSet('mountedActions.0.data.linked_entity', null)
            ->assertSet('mountedActions.0.data.linked_entity_id', null)
            ->set('mountedActions.0.data.linked_entity', 'company')
            ->set('mountedActions.0.data.linked_entity_id', '77')
            ->set('mountedActions.0.data.target_entity', 'lead')
            ->assertSet('mountedActions.0.data.linked_entity', 'company')
            ->assertSet('mountedActions.0.data.linked_entity_id', '77')
            ->set('mountedActions.0.data.linked_entity', 'customer')
            ->assertSet('mountedActions.0.data.linked_entity_id', null);
    }

    public function test_second_type_and_id_accept_workflow_expressions_without_changing_them(): void
    {
        $type = '{{ $vars.linked_type }}';
        $id = '{{ $vars.contact_id }}';
        $page = $this->editor()
            ->set('mountedActions.0.data.target_entity', 'lead')
            ->set('mountedActions.0.data.target_entity_id', '42')
            ->set('mountedActions.0.data.linked_entity', $type)
            ->set('mountedActions.0.data.linked_entity_id', $id)
            ->call('callMountedAction')->assertHasNoErrors();
        $config = $page->get('workflowActions')[0]['config'];
        $this->assertSame($type, $config['linked_entity']);
        $this->assertSame($id, $config['linked_entity_id']);
        Http::assertNothingSent();
    }

    public function test_static_validation_normalizes_aliases_and_defers_dynamic_types(): void
    {
        foreach (['lead' => 'leads', 'contact' => 'contacts', 'company' => 'companies', 'customer' => 'customers'] as $source => $alias) {
            foreach (['amocrm_link_entity', 'amocrm_unlink_entity'] as $type) {
                $this->assertContains(WorkflowEntityLinks::SAME_TYPE_ERROR, WorkflowDefinitionValidator::configIssues($type, ['target_entity' => strtoupper($alias), 'linked_entity' => $source]));
            }
        }
        $this->assertSame([], WorkflowDefinitionValidator::configIssues('amocrm_link_entity', ['target_entity' => 'lead', 'linked_entity' => 'contact']));
        $this->assertSame([], WorkflowDefinitionValidator::configIssues('amocrm_link_entity', ['target_entity' => '{{ $json.entity }}', 'linked_entity' => 'contact']));
    }

    public function test_execution_rejects_same_types_before_sending_any_link_or_unlink_request(): void
    {
        Http::fake();
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $account = (new Account)->forceFill(['id' => 1, 'endpoint' => 'https://workflow-links.test', 'access_token' => 'test-only']);
        foreach (['linkEntity', 'unlinkEntity'] as $name) {
            $method = new \ReflectionMethod($executor, $name);
            $client = (new \ReflectionClass($method->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
            foreach (['lead' => 'leads', 'contact' => 'contacts', 'company' => 'companies', 'customer' => 'customers'] as $source => $alias) {
                $result = $method->invoke($executor, $client, $account, ['entity_source' => 'manual', 'target_entity' => $source, 'target_entity_id' => 42, 'linked_entity' => $alias, 'linked_entity_id' => 77], null);
                $this->assertFalse($result['success']);
                $this->assertSame(WorkflowEntityLinks::SAME_TYPE_ERROR, $result['error']);
            }
        }
        $context = new WorkflowContext;
        $context->setVariable('_workflow_values', ['source_type' => 'contacts', 'linked_type' => 'contact']);
        $config = $context->resolve(['entity_source' => 'manual', 'target_entity' => '{{ $vars.source_type }}', 'target_entity_id' => 42, 'linked_entity' => '{{ $vars.linked_type }}', 'linked_entity_id' => 77]);
        $this->assertSame('contacts', $config['target_entity']);
        $result = (new \ReflectionMethod($executor, 'linkEntity'))->invoke($executor, $client, $account, $config, $context);
        $this->assertFalse($result['success']);
        $this->assertSame(WorkflowEntityLinks::SAME_TYPE_ERROR, $result['error']);
        Http::assertNothingSent();
    }
}
