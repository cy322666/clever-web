<?php

namespace Tests\Unit\Support;

use App\Models\Integrations\Sqns\Setting;
use App\Support\Integrations\PricingView;
use Tests\TestCase;

class PricingViewTest extends TestCase
{
    public function test_renders_all_sqns_plans_in_order(): void
    {
        $html = PricingView::sidebarHtml(Setting::$cost, showSavings: false)->toHtml();
        $prices = [
            '2 990 руб',
            '7 990 руб',
            '14 900 руб',
            '24 900 руб',
            '39 900 руб',
        ];

        foreach ($prices as $price) {
            $this->assertStringContainsString($price, $html);
        }

        $positions = array_map(fn (string $price): int|false => strpos($html, $price), $prices);

        $this->assertSame($positions, collect($positions)->sort()->values()->all());
        $this->assertStringNotContainsString('экономия', $html);
    }

    public function test_optional_plans_do_not_appear_for_standard_pricing(): void
    {
        $html = PricingView::sidebarHtml([])->toHtml();

        $this->assertStringNotContainsString('3 месяца', $html);
        $this->assertStringNotContainsString('24 месяца', $html);
        $this->assertStringContainsString('2 990 ₽', $html);
        $this->assertStringContainsString('14 900 ₽', $html);
        $this->assertStringContainsString('24 900 ₽', $html);
        $this->assertStringContainsString('экономия 3 000', $html);
        $this->assertStringContainsString('экономия 7 000', $html);
    }
}
