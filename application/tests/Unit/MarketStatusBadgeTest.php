<?php

namespace Tests\Unit;

use App\Filament\App\Widgets\Market;
use App\Models\App;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class MarketStatusBadgeTest extends TestCase
{
    public function test_expired_status_shows_how_many_days_ago_it_expired(): void
    {
        Carbon::setTestNow('2026-09-19 12:00:00');

        $app = (new App())->forceFill([
            'status' => App::STATE_EXPIRES,
            'expires_tariff_at' => '2026-08-16',
        ]);

        $method = new ReflectionMethod(Market::class, 'statusBadgeText');

        $this->assertSame('Истёк 34 дн.', $method->invoke(null, $app));
    }
}
