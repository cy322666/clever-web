<?php

namespace Tests\Unit\AmoCrm;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

class ThrottleRedisConfigTest extends TestCase
{
    public function test_throttle_connection_shares_the_store_with_bounded_io(): void
    {
        $previousContainer = Container::getInstance();
        new Application(dirname(__DIR__, 3));

        try {
            $config = require __DIR__.'/../../../config/database.php';
            $redis = $config['redis'];
            $throttle = $redis['amocrm_throttle'];

            foreach (['url', 'host', 'username', 'password', 'port', 'database'] as $key) {
                // Boolean assertions avoid printing connection secrets on failure.
                $this->assertTrue($redis['default'][$key] === $throttle[$key], "Shared Redis {$key} differs.");
            }

            $this->assertSame(1.0, $throttle['timeout']);
            $this->assertSame(1.0, $throttle['read_timeout']);
            $this->assertSame(0, $throttle['max_retries']);
            $this->assertArrayNotHasKey('prefix', $throttle);
            $this->assertArrayNotHasKey('timeout', $redis['default']);
            $this->assertArrayNotHasKey('read_timeout', $redis['default']);
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    public function test_throttle_uses_the_bounded_connection_by_default(): void
    {
        $key = 'AMOCRM_THROTTLE_REDIS_CONNECTION';
        $previousEnv = $_ENV[$key] ?? null;
        $previousServer = $_SERVER[$key] ?? null;
        $previousProcess = getenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        try {
            $config = require __DIR__.'/../../../config/amocrm-throttle.php';

            $this->assertSame('amocrm_throttle', $config['redis_connection']);
        } finally {
            if ($previousEnv !== null) {
                $_ENV[$key] = $previousEnv;
            }
            if ($previousServer !== null) {
                $_SERVER[$key] = $previousServer;
            }
            putenv($previousProcess === false ? $key : $key.'='.$previousProcess);
        }
    }
}
