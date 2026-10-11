<?php

namespace Tests\Feature\Core;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class RedisNetworkBoundaryTest extends TestCase
{
    public function test_redis_is_published_only_on_loopback(): void
    {
        $compose = Yaml::parseFile(base_path('../docker-compose.yml'));

        $this->assertSame(['127.0.0.1:6379:6379'], $compose['services']['redis']['ports']);
        $this->assertContains('redis-data:/data', $compose['services']['redis']['volumes']);
        $this->assertSame(['redis-server', '--appendonly', 'yes'], $compose['services']['redis']['command']);

        foreach (['app', 'supervisor'] as $service) {
            $this->assertSame('redis', $compose['services'][$service]['environment']['REDIS_HOST']);
        }
    }

    public function test_production_override_does_not_reopen_redis(): void
    {
        $compose = Yaml::parseFile(base_path('../monitoring/docker-compose.monitoring.prod.yml'));

        $this->assertArrayNotHasKey('ports', $compose['services']['redis'] ?? []);
        $this->assertArrayNotHasKey('network_mode', $compose['services']['redis'] ?? []);
    }
}
