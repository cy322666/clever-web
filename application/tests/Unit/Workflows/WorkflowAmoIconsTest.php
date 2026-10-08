<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowAmoIcons;
use BladeUI\Icons\Factory;
use Tests\TestCase;

class WorkflowAmoIconsTest extends TestCase
{
    public function test_tag_queries_use_tag_icons_in_every_entity_and_the_root_group(): void
    {
        $this->assertSame('heroicon-o-tag', WorkflowAmoIcons::entity('Теги'));
        foreach (['leads', 'contacts', 'companies', 'customers'] as $entity) {
            $this->assertSame('heroicon-o-tag', WorkflowAmoIcons::action('amocrm_read', ['operation'=>$entity.'.tags'], 'fallback'));
        }
    }

    public function test_digital_pipeline_has_a_clear_stage_transition_without_tiny_grid_details(): void
    {
        $svg = simplexml_load_string(app(Factory::class)->svg('amocrm-digital-pipeline')->toHtml());
        $this->assertSame('2', (string)$svg['stroke-width']);
        $this->assertCount(2, $svg->path);
        $this->assertCount(0, $svg->rect);
        $this->assertSame('M7 12h10m-3-3 3 3-3 3', (string)$svg->path[1]['d']);
    }
    public function test_conversations_use_imbox_in_the_library_and_events(): void
    {
        $this->assertSame('amocrm-imbox',WorkflowAmoIcons::entity('communication'));
        $this->assertSame('amocrm-imbox',WorkflowAmoIcons::trigger('amocrm-add-talk','fallback'));
        $svg = simplexml_load_string(app(Factory::class)->svg('amocrm-imbox')->toHtml());
        $this->assertCount(3,$svg->g->circle);
        $this->assertSame('white',(string)$svg->g['fill']);
    }
    public function test_task_and_customer_icons_use_the_filled_two_tone_style(): void
    {
        foreach (['task', 'customer'] as $entity) {
            $svg = app(Factory::class)->svg('amocrm-'.$entity)->toHtml();
            $this->assertStringContainsString('fill="currentColor"', $svg);
            $this->assertMatchesRegularExpression('/(?:fill|stroke)="white"/', $svg);
        }
    }

    public function test_the_dollar_is_filled_but_its_outer_ring_stays_an_outline(): void
    {
        $svg = simplexml_load_string(app(Factory::class)->svg('amocrm-lead')->toHtml());
        $this->assertSame('currentColor', (string) $svg->g->path['fill']);
        $this->assertSame('none', (string) $svg->path['fill']);
    }

    public function test_repeated_deal_icons_have_independent_clipping_references(): void
    {
        $first = \Illuminate\Support\Facades\Blade::render('<x-workflow-icon icon="amocrm-lead"/>');
        $second = \Illuminate\Support\Facades\Blade::render('<x-workflow-icon icon="amocrm-lead"/>');
        preg_match_all('/\bid=["\']([^"\']+)["\']/', $first, $ids);
        $this->assertNotEmpty($ids[1]);
        foreach ($ids[1] as $id) $this->assertStringNotContainsString($id, $second);
        $this->assertStringContainsString('workflow-amo-icon', $first);
        $this->assertStringNotContainsString('url(#b)', $first);
        $this->assertStringContainsString('workflow-amo-icon', \Illuminate\Support\Facades\Blade::render('<x-workflow-icon icon="heroicon-o-user" :amo="true"/>'));
        $this->assertStringNotContainsString('workflow-amo-icon', \Illuminate\Support\Facades\Blade::render('<x-workflow-icon icon="heroicon-o-play"/>'));
    }

    public function test_entities_and_read_operations_use_the_original_icons(): void
    {
        foreach (['lead' => 'lead', 'customers' => 'customer', 'Задачи' => 'task', 'Списки и товары' => 'catalog'] as $entity => $icon) {
            $this->assertSame('amocrm-'.$icon, WorkflowAmoIcons::entity($entity));
        }
        foreach (['leads.list' => 'lead', 'elements.list' => 'catalog', 'catalog_fields.list' => 'catalog'] as $operation => $icon) {
            $this->assertSame('amocrm-'.$icon, WorkflowAmoIcons::action('amocrm_read', ['operation' => $operation], 'fallback'));
        }
        $this->assertSame('amocrm-task', WorkflowAmoIcons::action('amocrm_create_task', ['target_entity' => 'lead'], 'fallback'));
        $this->assertSame('amocrm-lead', WorkflowAmoIcons::trigger('amocrm-add-lead', 'fallback'));
        $this->assertSame('amocrm-customer', WorkflowAmoIcons::trigger('amocrm-update-customer', 'fallback'));
    }

    public function test_unrelated_nodes_keep_their_icons(): void
    {
        $this->assertSame('original', WorkflowAmoIcons::entity('unknown', 'original'));
        $this->assertSame('original', WorkflowAmoIcons::action('control-condition', ['entity' => 'lead'], 'original'));
        $this->assertSame('original', WorkflowAmoIcons::trigger('schedule', 'original'));
    }

    public function test_all_original_assets_render_with_theme_inherited_colors(): void
    {
        foreach (['lead', 'customer', 'task', 'catalog'] as $entity) {
            $svg = app(Factory::class)->svg('amocrm-'.$entity)->toHtml();
            $this->assertStringContainsString('<svg', $svg);
            $this->assertStringContainsString('currentColor', $svg);
            $this->assertDoesNotMatchRegularExpression('/(?:fill|stroke)="#[a-f0-9]+"/i', $svg);
        }
    }

    public function test_note_and_company_icons_describe_the_operation_not_the_parent_entity(): void
    {
        foreach (['lead', 'company', 'contact'] as $parent) {
            $this->assertSame('heroicon-o-document-text', WorkflowAmoIcons::action('amocrm_add_note', ['target_entity' => $parent], 'fallback'));
        }
        $this->assertSame('heroicon-o-document-text', WorkflowAmoIcons::trigger('amocrm-add-note-lead', 'fallback'));
        $this->assertSame('heroicon-o-document-text', WorkflowAmoIcons::action('amocrm_read', ['operation' => 'leads.notes.one'], 'fallback'));
        $this->assertSame('heroicon-o-building-office-2', WorkflowAmoIcons::action('amocrm_update_company_fields', ['target_entity' => 'company'], 'fallback'));
        $this->assertSame('heroicon-o-building-office-2', WorkflowAmoIcons::entity('Компании'));
    }
}
