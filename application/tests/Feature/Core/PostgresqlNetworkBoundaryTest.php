<?php

namespace Tests\Feature\Core;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class PostgresqlNetworkBoundaryTest extends TestCase
{
    public function test_postgresql_is_published_only_on_loopback(): void
    {
        $compose = Yaml::parseFile(base_path('../docker-compose.yml'));

        $this->assertSame(['127.0.0.1:5425:5432'], $compose['services']['postgresql']['ports']);
        $this->assertArrayNotHasKey('network_mode', $compose['services']['postgresql']);
        $this->assertContains('postgresql', $compose['services']['postgresql']['networks']['default']['aliases']);

        foreach (['app', 'supervisor'] as $service) {
            $this->assertSame('service_healthy', $compose['services'][$service]['depends_on']['postgresql']['condition']);
        }
    }

    public function test_production_override_does_not_reopen_postgresql(): void
    {
        $compose = Yaml::parseFile(base_path('../monitoring/docker-compose.monitoring.prod.yml'));

        $this->assertArrayNotHasKey('ports', $compose['services']['postgresql'] ?? []);
        $this->assertArrayNotHasKey('network_mode', $compose['services']['postgresql'] ?? []);
    }
}
