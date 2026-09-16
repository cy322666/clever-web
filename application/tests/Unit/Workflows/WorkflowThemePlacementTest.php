<?php

namespace Tests\Unit\Workflows;

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Routing\Route;
use Tests\TestCase;

class WorkflowThemePlacementTest extends TestCase
{
    public function test_history_navigation_overrides_legacy_important_button_backgrounds(): void
    {
        $css = file_get_contents(resource_path('css/filament-workflows.css'));
        $rules = [
            '.workflow-history-page .workflow-workbench__quick-action' => ['background: transparent !important', 'color: #697482 !important'],
            '.dark .workflow-history-page .workflow-workbench__quick-action' => ['background: transparent !important', 'color: var(--workflow-dark-text) !important'],
            '.dark .workflow-history-page .workflow-workbench__quick-action:is(:hover, :focus-visible)' => ['background: var(--workflow-dark-hover) !important'],
        ];
        foreach ($rules as $selector => $declarations) {
            $this->assertSame(1, preg_match('/^'.preg_quote($selector, '/').'\s*\{([^}]+)\}/m', $css, $match));
            foreach ($declarations as $declaration) $this->assertStringContainsString($declaration, $match[1]);
        }
    }

    public function test_editor_toolbar_has_no_theme_switch(): void
    {
        \Tests\Support\WorkflowCanvasDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)->assertDontSee('Переключить тему');
    }

    public function test_theme_is_registered_next_to_profile_on_overview_pages_only(): void
    {
        $panel = \Filament\Facades\Filament::getPanel('app');
        $panel->boot();
        foreach (['index', 'analytics'] as $page) {
            request()->setRouteResolver(fn () => (new Route('GET', '/panel/workflows', fn () => ''))->name('filament.app.resources.workflows.'.$page));
            $this->assertStringContainsString('Переключить тему', (string) FilamentView::renderHook(PanelsRenderHook::USER_MENU_BEFORE));
        }
        request()->setRouteResolver(fn () => (new Route('GET', '/panel/workflows/1/edit', fn () => ''))->name('filament.app.resources.workflows.edit'));
        $this->assertStringNotContainsString('Переключить тему', (string) FilamentView::renderHook(PanelsRenderHook::USER_MENU_BEFORE));
        request()->setRouteResolver(fn () => (new Route('GET', '/panel/workflow-runs', fn () => ''))->name('filament.app.resources.workflow-runs.index'));
        $this->assertStringNotContainsString('Переключить тему', (string) FilamentView::renderHook(PanelsRenderHook::USER_MENU_BEFORE));
        $history = file_get_contents(resource_path('views/filament/workflow-builder/workflow-history-page.blade.php'));
        $this->assertStringNotContainsString('workflow-theme-toggle', $history);
        $this->assertStringNotContainsString('heroicon-o-pencil', $history);
        $this->assertStringNotContainsString('aria-label="История"', $history);
    }
}
