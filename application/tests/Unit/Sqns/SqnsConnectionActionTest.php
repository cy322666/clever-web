<?php

namespace Tests\Unit\Sqns;

use App\Filament\Resources\Integrations\Sqns\Pages\EditSqns;
use App\Models\Integrations\Sqns\Setting;
use Filament\Actions\Action;
use Tests\TestCase;

class SqnsConnectionActionTest extends TestCase
{
    public function test_disconnected_state_connects_without_opening_disconnect_confirmation(): void
    {
        $page = $this->pageWith(new Setting);
        $action = $page->connectionAction();

        $this->assertSame('Подключить SQNS', $action->getLabel());
        $this->assertSame('warning', $action->getColor());
        $this->assertFalse($action->shouldOpenModal(fn (): bool => false));
    }

    public function test_connected_state_offers_disconnect_with_confirmation(): void
    {
        $page = $this->pageWith(new Setting(['token' => 'test-token']));
        $action = $page->connectionAction();

        $this->assertSame('Отключить SQNS', $action->getLabel());
        $this->assertSame('danger', $action->getColor());
        $this->assertTrue($action->shouldOpenModal(fn (): bool => false));
        $this->assertSame('Отключить SQNS?', $action->getModalHeading());
    }

    private function pageWith(Setting $setting): TestableEditSqns
    {
        $page = new TestableEditSqns;
        $page->record = $setting;

        return $page;
    }
}

class TestableEditSqns extends EditSqns
{
    public function connectionAction(): Action
    {
        return $this->sqnsConnectionAction();
    }
}
