<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\ListWorkflows;
use Tests\TestCase;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class WorkflowPageTitleTest extends TestCase
{
    public function test_workflow_list_uses_the_product_name_in_its_browser_title(): void
    {
        app()->setLocale('ru');

        $this->assertSame('Потоки', WorkflowResource::getPluralModelLabel());
        $this->assertSame('Потоки', (new ListWorkflows)->getTitle());
    }

    public function test_workflow_browser_title_has_no_platform_suffix(): void
    {
        $request = Request::create('/panel/workflows');
        $request->setRouteResolver(fn () => (new Route('GET', '/panel/workflows', []))->name('filament.app.resources.workflows.index'));
        $this->app->instance('request', $request);
        $this->assertSame('', Filament::getPanel('app')->getBrandName());

        app()->setLocale('ru');
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $html = \Illuminate\Support\Facades\Blade::render(
            '<x-filament-panels::layout.base :livewire="$page">Test</x-filament-panels::layout.base>',
            ['page' => new ListWorkflows],
        );
        preg_match('/<title>(.*?)<\/title>/s', $html, $matches);
        $this->assertSame('Потоки', trim($matches[1] ?? ''));
    }

    public function test_other_pages_keep_their_platform_name(): void
    {
        $request = Request::create('/panel/dashboard');
        $request->setRouteResolver(fn () => (new Route('GET', '/panel/dashboard', []))->name('filament.app.pages.dashboard'));
        $this->app->instance('request', $request);
        $this->assertSame('CleverCRM', Filament::getPanel('app')->getBrandName());
    }
}
