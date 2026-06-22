<?php

namespace Tests\Feature\Core;

use App\Services\Core\MonitoringCache;
use Tests\TestCase;

class SlowQueryMetricsTest extends TestCase
{
    public function test_metrics_expose_slow_query_context_and_last_event(): void
    {
        config([
            'alerts.cache_store' => 'array',
            'database.monitoring.slow_query_threshold_ms' => 750,
        ]);

        $contextKey = sha1('pgsql|http|filament.app.pages.dashboard');

        MonitoringCache::forever('monitoring:db:slow_queries:total', 3);
        MonitoringCache::forever('monitoring:db:slow_queries:context_index', [
            $contextKey => [
                'connection' => 'pgsql',
                'source' => 'http',
                'name' => 'filament.app.pages.dashboard',
            ],
        ]);
        MonitoringCache::forever('monitoring:db:slow_queries:context_total:' . $contextKey, 2);
        MonitoringCache::put('monitoring:db:slow_queries:last_ms', 1234.5, 3600);
        MonitoringCache::put('monitoring:db:slow_queries:last_seen_unixtime', now()->timestamp, 3600);
        MonitoringCache::put('monitoring:db:slow_queries:last_context', [
            'connection' => 'pgsql',
            'source' => 'http',
            'name' => 'filament.app.pages.dashboard',
        ], 3600);

        $response = $this->get('/metrics');

        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringContainsString('clever_db_slow_queries_total 3', $body);
        $this->assertStringContainsString(
            'clever_db_slow_queries_by_context_total{connection="pgsql",source="http",name="filament.app.pages.dashboard"} 2',
            $body,
        );
        $this->assertStringContainsString('clever_db_last_slow_query_ms 1234.5', $body);
        $this->assertStringContainsString('clever_db_last_slow_query_age_seconds ', $body);
        $this->assertStringContainsString('clever_db_slow_query_threshold_ms 750', $body);
        $this->assertStringContainsString(
            'clever_db_last_slow_query_info{connection="pgsql",source="http",name="filament.app.pages.dashboard"} 1',
            $body,
        );
    }
}
