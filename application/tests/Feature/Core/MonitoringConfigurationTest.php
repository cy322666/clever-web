<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

class MonitoringConfigurationTest extends TestCase
{
    public function test_monitoring_cache_is_isolated_from_application_cache_clear(): void
    {
        $applicationTable = config('cache.stores.database.table');
        $monitoringTable = config('cache.stores.monitoring.table');

        $this->assertSame('database', config('cache.stores.monitoring.driver'));
        $this->assertSame('monitoring_cache', $monitoringTable);
        $this->assertNotSame($applicationTable, $monitoringTable);
    }

    public function test_active_alert_panels_use_instant_queries(): void
    {
        $path = dirname(__DIR__, 4) . '/monitoring/grafana/dashboards/clever-web-simple.json';
        $dashboard = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $panels = collect($dashboard['panels'])
            ->filter(fn(array $panel): bool => str_starts_with($panel['title'] ?? '', 'Активные алерты'));

        $this->assertCount(1, $panels);

        foreach ($panels as $panel) {
            $target = $panel['targets'][0];

            $this->assertTrue($target['instant'] ?? false, $panel['title']);
            $this->assertFalse($target['range'] ?? true, $panel['title']);
        }

        $summaryPanel = $panels->firstWhere('title', 'Активные алерты');

        $this->assertStringContainsString('or vector(0)', $summaryPanel['targets'][0]['expr']);
    }

    public function test_dashboard_only_uses_prometheus(): void
    {
        $path = dirname(__DIR__, 4) . '/monitoring/grafana/dashboards/clever-web-simple.json';
        $dashboard = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $datasources = collect($dashboard['panels'])
            ->pluck('datasource.uid')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->assertSame(['prometheus'], $datasources);
    }
}
