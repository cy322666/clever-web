<?php

namespace Tests\Feature\Core;

use Filament\Facades\Filament;
use Tests\TestCase;

class PanelSidebarTest extends TestCase
{
    public function test_application_navigation_uses_a_compact_sidebar(): void
    {
        $panel = Filament::getPanel('app');

        $this->assertSame('13rem', $panel->getSidebarWidth());
        $this->assertSame('4.5rem', $panel->getCollapsedSidebarWidth());
        $this->assertTrue($panel->isSidebarCollapsibleOnDesktop());
        $this->assertFalse($panel->isSidebarFullyCollapsibleOnDesktop());
    }
}
