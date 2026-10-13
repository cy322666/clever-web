<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowNodeAppearance;
use BladeUI\Icons\Factory;
use Illuminate\Support\Facades\Blade;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;
use Tests\TestCase;

class WorkflowNodeAppearanceTest extends TestCase
{
    public function test_selected_icons_use_the_approved_original_not_a_library_substitution(): void
    {
        $this->assertSame('828411cf0c58e17fbdbe8cbe6e2278aab5fd3575d8d3ad47ffda150c511b9bad', hash_file('sha256', resource_path('images/workflow-nodes/duotone-approved.png')));
        foreach (['amocrm_create_contact'=>'contact-create', 'amocrm_update_contact_fields'=>'contact-update', 'amocrm_create_lead'=>'lead-create', 'control-condition'=>'condition', 'workflow_loop'=>'loop', 'workflow_set_variable'=>'assign', 'http_request'=>'http', 'telegram_send_message'=>'telegram'] as $type=>$artwork) {
            $html = Blade::render('<x-workflow-node-icon :appearance="$appearance"/>', ['appearance'=>WorkflowNodeAppearance::action($type)]);
            $this->assertStringContainsString('data-node-artwork="'.$artwork.'"', $html);
            $this->assertStringNotContainsString('<svg', $html);
        }
    }

    public function test_javascript_node_shows_its_language_instead_of_a_generic_code_symbol(): void
    {
        foreach ([false, true] as $disabled) {
            $html = Blade::render('<x-filament-workflows::workflows.action-card :action="$action" :read-only="true"/>', [
                'action'=>['id'=>'code','type'=>'workflow_javascript','name'=>'Преобразовать данные','disabled'=>$disabled],
            ]);
            $this->assertStringContainsString('data-node-family="data"', $html);
            $this->assertStringContainsString('data-node-language="javascript" aria-hidden="true">JS</span>', $html);
            $this->assertStringNotContainsString('data-node-artwork="javascript"', $html);
            $this->assertStringNotContainsString('wficon-code', $html);
        }
    }

    public function test_crm_operations_have_distinct_action_cues_and_keep_the_entity(): void
    {
        foreach (['contact' => 'user', 'lead' => 'briefcase', 'company' => 'buildings'] as $entity => $glyph) {
            foreach (['amocrm_find_entity' => 'magnifying-glass', 'amocrm_create_'.$entity => 'plus', 'amocrm_update_'.$entity.'_fields' => 'pencil-simple'] as $type => $modifier) {
                $tone = ['magnifying-glass'=>'query', 'plus'=>'create', 'pencil-simple'=>'update'][$modifier];
                $this->assertSame(['family'=>'crm', 'glyph'=>$glyph, 'modifier'=>$modifier, 'tone'=>$tone], WorkflowNodeAppearance::action($type, ['target_entity'=>$entity]));
            }
        }
        $this->assertSame('note', WorkflowNodeAppearance::action('amocrm_add_note', ['target_entity'=>'lead'])['glyph']);
        $this->assertSame('tag', WorkflowNodeAppearance::action('amocrm_read', ['operation'=>'contacts.tags'])['glyph']);
        $this->assertSame('briefcase', WorkflowNodeAppearance::action('amocrm_read', ['operation'=>'leads.list'])['glyph']);
    }

    public function test_families_are_semantic_and_not_execution_statuses(): void
    {
        foreach (['control-condition'=>'control', 'workflow_loop'=>'control', 'workflow_filter_list'=>'control', 'workflow_delay'=>'control', 'workflow_set_variable'=>'data', 'workflow_javascript'=>'data', 'http_request'=>'http', 'telegram_send_message'=>'telegram', 'unknown'=>'neutral'] as $type=>$family) {
            $this->assertSame($family, WorkflowNodeAppearance::action($type)['family']);
        }
        foreach (['manual'=>'play', 'schedule'=>'clock', 'generic-webhook'=>'webhooks-logo'] as $type=>$glyph) {
            $this->assertSame(['family'=>'trigger','glyph'=>$glyph,'modifier'=>null,'tone'=>'trigger'], WorkflowNodeAppearance::trigger($type));
        }
    }

    public function test_all_start_methods_share_one_color_without_replacing_their_icons(): void
    {
        $types = array_column(app(TriggerRegistry::class)->getAllWithMetadata(), 'type');
        $this->assertContains('manual', $types);
        $this->assertContains('schedule', $types);
        $this->assertContains('generic-webhook', $types);
        foreach ([...$types, 'future-trigger'] as $type) {
            $this->assertSame('trigger', WorkflowNodeAppearance::trigger($type)['tone']);
            foreach ([false, true] as $readOnly) {
                $html = Blade::render('<x-filament-workflows::workflows.trigger-card :type="$type" :read-only="$readOnly"/>', compact('type', 'readOnly'));
                $this->assertStringContainsString('data-node-tone="trigger"', $html, $type);
            }
        }
    }

    public function test_all_registered_nodes_render_local_library_assets(): void
    {
        $appearances = [];
        foreach (app(ActionRegistry::class)->getAllWithMetadata() as $meta) {
            $appearances[] = WorkflowNodeAppearance::action($meta['type'], [], $meta['icon'] ?? '');
        }
        foreach (app(TriggerRegistry::class)->getAllWithMetadata() as $meta) {
            $appearances[] = WorkflowNodeAppearance::trigger($meta['type'], $meta['icon'] ?? '');
        }
        $this->assertGreaterThan(30, count($appearances));
        foreach ($appearances as $appearance) {
            $this->assertStringContainsString('[data-node-tone="'.$appearance['tone'].'"]', file_get_contents(resource_path('css/filament-workflows.css')));
            $html = Blade::render('<x-workflow-node-icon :appearance="$appearance"/>', compact('appearance'));
            $this->assertTrue(str_contains($html, '<svg') || str_contains($html, 'data-node-artwork=') || str_contains($html, 'data-node-language="javascript"'));
            $this->assertStringContainsString('aria-hidden="true"', $html);
            $this->assertStringNotContainsString('<script', $html);
            if (!str_contains($html, 'data-node-artwork=') && !str_contains($html, 'data-node-language=')) {
                $this->assertStringContainsString('workflow-node-glyph__'.($appearance['modifier'] ? 'action' : 'main'), $html);
            }
        }
    }

    public function test_entity_operations_share_blue_while_other_types_keep_their_colors_in_editor_and_history(): void
    {
        $types = ['amocrm_find_entity'=>'query', 'amocrm_create_contact'=>'create', 'amocrm_update_contact_fields'=>'update',
            'control-condition'=>'condition', 'workflow_loop'=>'loop', 'workflow_filter_list'=>'filter', 'workflow_delay'=>'delay',
            'workflow_set_variable'=>'variable', 'workflow_javascript'=>'javascript', 'http_request'=>'http', 'telegram_send_message'=>'telegram'];
        $css = file_get_contents(resource_path('css/filament-workflows.css'));
        $colors = [];
        foreach ($types as $type => $tone) {
            $this->assertSame($tone, WorkflowNodeAppearance::action($type)['tone']);
            preg_match('/\[data-node-tone="'.$tone.'"\] \{ --workflow-node-fill: (#[a-f0-9]+); \}/', $css, $matches);
            $this->assertNotEmpty($matches, $type);
            $colors[$tone] = $matches[1];
            foreach ([false, true] as $readOnly) {
                $html = Blade::render('<x-filament-workflows::workflows.action-card :action="$action" :read-only="$readOnly"/>', [
                    'action'=>['id'=>'probe', 'type'=>$type, 'name'=>'Одно название', 'disabled'=>true], 'readOnly'=>$readOnly,
                ]);
                $this->assertStringContainsString('data-node-tone="'.$tone.'"', $html);
            }
        }
        $this->assertSame('#2878df', $colors['query']);
        $this->assertSame($colors['query'], $colors['create']);
        $this->assertSame($colors['query'], $colors['update']);
        $this->assertCount(count($types) - 2, array_unique($colors));
        $this->assertStringContainsString('background-blend-mode: luminosity;', $css);
        $this->assertStringContainsString('.workflow-node-card--disabled .workflow-node-artwork { filter: grayscale(1);', $css);
    }

    public function test_readonly_cards_share_the_appearance_without_changing_ports(): void
    {
        foreach (['control-condition'=>['yes','no'], 'workflow_loop'=>['done','each'], 'amocrm_create_contact'=>['output']] as $type=>$ports) {
            $html = Blade::render('<x-filament-workflows::workflows.action-card :action="$action" :read-only="true" :execution-count="2"/>', ['action'=>['id'=>'probe','type'=>$type,'name'=>'Проверка','disabled'=>true]]);
            $this->assertStringContainsString('data-node-family=', $html);
            $this->assertStringContainsString('workflow-node-card--disabled', $html);
            $this->assertStringContainsString('workflow-node-status--success', $html);
            $this->assertStringContainsString('workflow-node-status--error', $html);
            $this->assertStringContainsString('workflow-node-port--input', $html);
            foreach ($ports as $port) $this->assertStringContainsString('workflow-node-port--output'.($port === 'output' ? '"' : '-'.$port), $html);
        }
        $trigger = Blade::render('<x-filament-workflows::workflows.trigger-card type="manual" :read-only="true"/>');
        $this->assertStringContainsString('data-node-family="trigger"', $trigger);
        $this->assertStringContainsString('workflow-node-port--output', $trigger);
    }

    public function test_vendored_icons_are_valid_self_contained_current_color_svgs(): void
    {
        foreach (glob(resource_path('svg/workflow-nodes/*.svg')) as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $svg = app(Factory::class)->svg('wficon-'.$name)->toHtml();
            $this->assertNotFalse(simplexml_load_string($svg), $name);
            $this->assertStringContainsString('viewBox="0 0 256 256"', $svg, $name);
            $this->assertStringContainsString('currentColor', $svg, $name);
            $this->assertDoesNotMatchRegularExpression('/<(script|image|foreignObject)|(?:href|onload|onclick)=/i', $svg, $name);
        }
        $this->assertStringContainsString('MIT', file_get_contents(resource_path('svg/workflow-nodes/LICENSE')));
    }
}
