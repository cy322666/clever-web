<?php

namespace Tests\Feature\Core;

use App\Filament\App\Pages\Dashboard;
use App\Providers\Filament\CatalogPanelProvider;
use Filament\Facades\Filament;
use Filament\Panel;
use Tests\TestCase;

class PanelErrorHandlingTest extends TestCase
{
    public function test_all_panels_use_native_livewire_error_handling(): void
    {
        $panels = Filament::getPanels();

        $this->assertArrayHasKey('app', $panels);

        foreach ($panels as $id => $panel) {
            $this->assertFalse($panel->hasErrorNotifications(), $id);
        }
    }

    public function test_dashboard_does_not_override_native_error_handling(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->assertFalse(app(Dashboard::class)->hasErrorNotifications());
    }

    public function test_catalog_inherits_native_handling_if_enabled_again(): void
    {
        $panel = (new CatalogPanelProvider($this->app))->panel(Panel::make());

        $this->assertFalse($panel->hasErrorNotifications());
    }

    public function test_future_panels_inherit_the_same_error_handling(): void
    {
        $this->assertFalse(Panel::make()->id('test-panel')->hasErrorNotifications());
    }

    public function test_normal_database_notifications_stay_enabled(): void
    {
        $this->assertTrue(Filament::getPanel('app')->hasDatabaseNotifications());
    }
}
