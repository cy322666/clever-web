<?php

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowBrowserDocument;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowBrowserDatabase;
use Tests\TestCase;

class WorkflowBrowserDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowBrowserDatabase::prepare();
        $this->actingAs(User::findOrFail(1));
        Http::preventStrayRequests();
    }

    private function workflow(): Workflow
    {
        return Workflow::create(['user_id' => 1, 'name' => 'Браузер', 'is_active' => false, 'failure_strategy' => 'stop',
            'definition' => ['trigger' => ['type' => 'manual', 'name' => 'Старт', 'config' => []], 'actions' => [
                ['id' => 'http', 'name' => 'Запрос', 'type' => 'http_request', 'config' => ['url' => 'https://example.test', 'headers' => '{"Authorization":"test-private-key"}', 'unknown_setting' => ['keep' => true]]],
            ], 'connections' => [['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:http']]]]);
    }

    public function test_document_save_preserves_keys_unknown_settings_and_graph(): void
    {
        $workflow = $this->workflow();
        $service = app(WorkflowBrowserDocument::class);
        $document = $service->document($workflow);
        $document['name'] = 'Новое название';
        $this->putJson('/workflow-editor/'.$workflow->id.'/document', ['document' => $document, 'version' => $service->version($workflow)])
            ->assertOk()->assertJsonPath('document.name', 'Новое название');
        $saved = $workflow->fresh();
        $this->assertSame('{"Authorization":"test-private-key"}', data_get($saved->definition, 'actions.0.config.headers'));
        $this->assertSame(['keep' => true], data_get($saved->definition, 'actions.0.config.unknown_setting'));
        $this->assertSame($document['definition']['connections'], $saved->definition['connections']);
    }

    public function test_stale_save_is_rejected_without_overwriting_another_edit(): void
    {
        $workflow = $this->workflow();
        $service = app(WorkflowBrowserDocument::class);
        $version = $service->version($workflow);
        $document = $service->document($workflow);
        $workflow->update(['name' => 'Другое окно']);
        $document['name'] = 'Старое окно';
        $this->putJson('/workflow-editor/'.$workflow->id.'/document', ['document' => $document, 'version' => $version])->assertStatus(409);
        $this->assertSame('Другое окно', $workflow->fresh()->name);
    }

    public function test_other_owner_cannot_read_refresh_debug_or_overwrite_workflow(): void
    {
        $workflow = $this->workflow();
        $service = app(WorkflowBrowserDocument::class);
        $document = $service->document($workflow);
        $version = $service->version($workflow);
        $this->actingAs(User::findOrFail(2));
        $this->putJson('/workflow-editor/'.$workflow->id.'/document', ['document' => $document, 'version' => $version])->assertNotFound();
        $this->postJson('/workflow-editor/'.$workflow->id.'/references', [])->assertNotFound();
        $this->postJson('/workflow-editor/'.$workflow->id.'/debug', ['command' => 'start'])->assertNotFound();
    }

    public function test_invalid_graph_is_rejected_for_inactive_drafts_too(): void
    {
        $workflow = $this->workflow();
        $service = app(WorkflowBrowserDocument::class);
        $document = $service->document($workflow);
        $document['definition']['connections'][0]['targetId'] = 'action:missing';
        $this->putJson('/workflow-editor/'.$workflow->id.'/document', ['document' => $document, 'version' => $service->version($workflow)])->assertUnprocessable();
        $this->assertSame('action:http', $workflow->fresh()->definition['connections'][0]['targetId']);
    }

    public function test_encrypted_legacy_telegram_token_stays_server_side_and_survives_local_copy(): void
    {
        $service = app(WorkflowBrowserDocument::class);
        $secret = \Illuminate\Support\Facades\Crypt::encryptString('test-only-token');
        $workflow = new Workflow(['definition' => ['trigger' => ['type' => 'manual'], 'actions' => [['id' => 'bot', 'type' => 'telegram_send_message', 'config' => ['bot_token_encrypted' => $secret, 'chat_id' => '1']]]]]);
        $document = $service->document($workflow);
        $this->assertStringNotContainsString($secret, json_encode($document));
        $copy = $document['definition']['actions'][0];
        $copy['id'] = 'copy';
        $document['definition']['actions'][] = $copy;
        $restored = $service->restoreSecrets($document['definition'], $workflow->definition);
        $this->assertSame($secret, $restored['actions'][1]['config']['bot_token_encrypted']);
        $this->assertArrayNotHasKey('_browser_secret_source', $restored['actions'][1]['config']);
    }

    public function test_browser_page_boots_once_without_workflow_data_in_livewire_snapshot(): void
    {
        $workflow = $this->workflow();
        $page = Livewire::test(\App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\BrowserEditWorkflow::class, ['record' => $workflow->id]);
        $page->assertSeeHtml('workflowBrowserEditor(')->assertSeeHtml('data-browser-boot');
        $this->assertFalse(property_exists($page->instance(), 'workflowActions'));
        $this->assertFalse(property_exists($page->instance(), 'definition'));
        $this->assertFalse(property_exists($page->instance(), 'savedWorkflowEditorState'));
    }

    public function test_node_debug_keeps_previous_outputs_and_loop_runs_only_one_node(): void
    {
        $workflow = $this->workflow();
        $definition = ['trigger' => ['type' => 'manual', 'config' => []], 'actions' => [
            ['id' => 'loop', 'type' => 'workflow_loop', 'name' => 'Цикл', 'config' => ['items' => [['id' => 10], ['id' => 20]], 'mode' => 'once', 'require_return' => true]],
            ['id' => 'next', 'type' => 'workflow_set_variable', 'name' => 'Следующая', 'config' => ['assignments' => [['name' => 'item_id', 'value' => '{{ $json.id }}']]]],
        ], 'connections' => [
            ['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:loop'],
            ['sourceId' => 'action:loop', 'sourcePort' => 'each', 'targetId' => 'action:next'],
            ['sourceId' => 'action:next', 'sourcePort' => 'output', 'targetId' => 'action:loop'],
        ]];
        $first = $this->postJson('/workflow-editor/'.$workflow->id.'/debug', ['command' => 'node', 'definition' => $definition, 'nodeId' => 'action:loop', 'input' => [], 'real' => false]);
        $first->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', 'loop')->assertJsonPath('results.0.display_output.id', 10);
        $this->postJson('/workflow-editor/'.$workflow->id.'/debug', ['command' => 'node', 'definition' => $definition, 'nodeId' => 'action:next', 'input' => [], 'real' => false, 'session' => $first->json('session')])
            ->assertOk()->assertJsonCount(2, 'results')->assertJsonPath('results.1.id', 'next')->assertJsonPath('results.1.display_output.item_id', 10);
    }

    public function test_step_debug_refuses_changed_graph_and_preserves_false_output(): void
    {
        $workflow = $this->workflow();
        $definition = ['trigger' => ['type' => 'manual', 'config' => []], 'actions' => [['id' => 'if', 'name' => 'Условие', 'type' => 'control-condition', 'config' => ['logic' => 'and', 'conditions' => [['left' => 'no', 'operator' => 'equals', 'right' => 'yes']]]]],
            'connections' => [['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:if']]];
        $first = $this->postJson('/workflow-editor/'.$workflow->id.'/debug', ['command' => 'start', 'definition' => $definition, 'input' => [], 'real' => false])->assertOk();
        $this->postJson('/workflow-editor/'.$workflow->id.'/debug', ['command' => 'step', 'definition' => $definition, 'session' => $first->json('session')])
            ->assertOk()->assertJsonPath('results.0.display_output', false)->assertJsonPath('status', 'completed');
        $definition['actions'][0]['config']['conditions'][0]['right'] = 'no';
        $this->postJson('/workflow-editor/'.$workflow->id.'/debug', ['command' => 'step', 'definition' => $definition, 'session' => $first->json('session')])->assertStatus(409);
    }

    public function test_draft_creation_is_inactive_and_unknown_definition_properties_survive(): void
    {
        $service = app(WorkflowBrowserDocument::class);
        $document = $service->document();
        $document['definition']['custom_metadata'] = ['private' => 'kept'];
        $response = $this->postJson('/workflow-editor/document', ['document' => $document, 'version' => null])->assertOk();
        $saved = Workflow::findOrFail($response->json('document.id'));
        $this->assertFalse($saved->is_active);
        $this->assertSame(['private' => 'kept'], $saved->definition['custom_metadata']);
    }

    public function test_duplicate_uses_current_draft_keeps_keys_and_leaves_original_untouched(): void
    {
        $workflow = $this->workflow();
        $service = app(WorkflowBrowserDocument::class);
        $document = $service->document($workflow);
        $document['name'] = 'Черновик (копия)';
        $document['is_active'] = true;
        $response = $this->postJson('/workflow-editor/'.$workflow->id.'/duplicate', ['document' => $document, 'version' => $service->version($workflow)])
            ->assertOk()->assertJsonPath('document.is_active', false);
        $this->assertNotSame($workflow->id, $response->json('document.id'));
        $this->assertSame('Браузер', $workflow->fresh()->name);
        $this->assertSame('{"Authorization":"test-private-key"}', $response->json('document.definition.actions.0.config.headers'));
    }
}
