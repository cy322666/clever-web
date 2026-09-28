<?php

namespace Tests\Unit;

use App\Filament\App\Widgets\Market;
use App\Models\App;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class MarketStatusBadgeTest extends TestCase
{
    public function test_new_integration_has_no_redundant_available_badge(): void
    {
        $app = (new App)->forceFill([
            'status' => App::STATE_CREATED,
        ]);

        $method = new ReflectionMethod(Market::class, 'statusBadgeText');

        $this->assertNull($method->invoke(null, $app));
    }

    public function test_connected_integration_keeps_its_paid_until_badge(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 28)->startOfDay());
        $app = (new App)->forceFill([
            'status' => App::STATE_ACTIVE,
            'expires_tariff_at' => '2027-02-09',
        ]);

        $method = new ReflectionMethod(Market::class, 'statusBadgeText');

        $this->assertSame('До 2027-02-09', $method->invoke(null, $app));
    }

    public function test_inactive_integration_keeps_its_warning_badge(): void
    {
        $app = (new App)->forceFill(['status' => App::STATE_INACTIVE]);
        $method = new ReflectionMethod(Market::class, 'statusBadgeText');

        $this->assertSame(App::STATE_INACTIVE_WORD, $method->invoke(null, $app));
    }

    public function test_expired_status_shows_how_many_days_ago_it_expired(): void
    {
        Carbon::setTestNow('2026-09-19 12:00:00');

        $app = (new App)->forceFill([
            'status' => App::STATE_EXPIRES,
            'expires_tariff_at' => '2026-08-16',
        ]);

        $method = new ReflectionMethod(Market::class, 'statusBadgeText');

        $this->assertSame('Истёк 34 дн.', $method->invoke(null, $app));
    }
}
