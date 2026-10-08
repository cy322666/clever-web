<?php

namespace Tests\Unit\AmoCrm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QueueVisibilitySafetyTest extends TestCase
{
    #[DataProvider('retryAfterOverrides')]
    public function test_redis_visibility_outlives_every_horizon_worker(string $override, int $expected): void
    {
        $key = 'REDIS_QUEUE_RETRY_AFTER';
        $previousEnv = $_ENV[$key] ?? null;
        $previousServer = $_SERVER[$key] ?? null;
        $previousProcess = getenv($key);
        $_ENV[$key] = $_SERVER[$key] = $override;
        putenv($key.'='.$override);

        try {
            $queue = require __DIR__.'/../../../config/queue.php';
            $horizon = require __DIR__.'/../../../config/horizon.php';

            $this->assertSame($expected, $queue['connections']['redis']['retry_after']);

            foreach ($horizon['environments'] as $environment => $supervisors) {
                foreach ($supervisors as $name => $overrides) {
                    $supervisor = array_replace($horizon['defaults'][$name], $overrides);
                    $connection = $queue['connections'][$supervisor['connection']];

                    $this->assertGreaterThanOrEqual(
                        $supervisor['timeout'] + 60,
                        $connection['retry_after'],
                        "$environment/$name can be delivered again before its worker times out.",
                    );
                }
            }
        } finally {
            if ($previousEnv === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previousEnv;
            }
            if ($previousServer === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $previousServer;
            }
            putenv($previousProcess === false ? $key : $key.'='.$previousProcess);
        }
    }

    public static function retryAfterOverrides(): array
    {
        return [
            'old unsafe visibility' => ['200', 960],
            'disabled visibility' => ['0', 960],
            'invalid override' => ['invalid', 960],
            'minimum safe visibility' => ['960', 960],
            'longer visibility' => ['1800', 1800],
        ];
    }
}
