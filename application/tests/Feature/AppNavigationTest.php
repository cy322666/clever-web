<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\Onboarding;
use App\Filament\Resources\Billing\SubscriptionPlanResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Tests\TestCase;

class AppNavigationTest extends TestCase
{
    public function test_user_sidebar_only_shows_integrations(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'is_root' => false]));
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $items = $this->navigationItems();

        $this->assertSame(['Интеграции'], $items->map(fn (NavigationItem $item): string => $item->getLabel())->all());
        $this->assertSame([Dashboard::getUrl()], $items->map(fn (NavigationItem $item): string => $item->getUrl())->all());
    }

    public function test_root_sidebar_keeps_admin_links_without_setup_or_tariffs(): void
    {
        $this->actingAs((new User)->forceFill(['id' => 1, 'is_root' => true]));
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->assertSame(
            ['Интеграции', 'Заявки на счет', 'Админ потоков'],
            $this->navigationItems()->map(fn (NavigationItem $item): string => $item->getLabel())->all(),
        );
    }

    public function test_hidden_pages_keep_their_direct_urls(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->assertSame(url('/panel/start'), Onboarding::getUrl());
        $this->assertSame(url('/panel/billing/subscription-plans'), SubscriptionPlanResource::getUrl());
    }

    private function navigationItems(): \Illuminate\Support\Collection
    {
        return collect(Filament::getPanel('app')->getNavigation())
            ->flatMap(fn (NavigationGroup $group): array => $group->getItems())
            ->values();
    }
}
