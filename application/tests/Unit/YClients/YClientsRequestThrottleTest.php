<?php

namespace Tests\Unit\YClients;

use App\Services\YClients\YClientsRequestThrottle;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class YClientsRequestThrottleTest extends TestCase
{
    private mixed $previousContainer;
    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $app = new Container;
        Facade::clearResolvedInstances();
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_waiting_worker_rechecks_a_new_cooldown_without_reserving_a_future_slot(): void
    {
        $limiter = $this->limiter([350, 2000, 0]);
        $limiter->acquire();
        $this->assertSame([350, 2000], $limiter->sleeps);
        $this->assertCount(3, $limiter->evaluations);
        foreach ($limiter->evaluations as $call) {
            $this->assertSame(YClientsRequestThrottle::ACQUIRE_SCRIPT, $call[0]);
            $this->assertSame($limiter->keys(), $call[1]);
            $this->assertSame([350], $call[2]);
        }
    }

    public function test_long_shared_cooldown_fails_before_http_instead_of_ignoring_the_budget(): void
    {
        $limiter = $this->limiter([60000]);
        try {
            $limiter->acquire();
            $this->fail('A long cooldown must not be bypassed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Request not sent', $e->getMessage());
            $this->assertSame([], $limiter->sleeps);
        }
    }

    #[DataProvider('invalidAdmissions')]
    public function test_malformed_redis_result_fails_closed(mixed $result): void
    {
        $this->expectException(RuntimeException::class);
        $this->limiter([$result])->acquire();
    }

    public static function invalidAdmissions(): array
    {
        return [[null], [false], [-1], ['-1'], ['not-a-wait'], [['wait' => 350]], [0.5]];
    }

    public function test_redis_failure_does_not_fall_back_to_per_process_admission_or_expose_credentials(): void
    {
        $redis = \Mockery::mock(\Illuminate\Redis\RedisManager::class);
        $redis->shouldReceive('connection')->once()->with('amocrm_throttle')
            ->andThrow(new RuntimeException('redis://private-user:secret@private-host'));
        Redis::swap($redis);
        try {
            (new YClientsRequestThrottle)->acquire();
            $this->fail('Redis is mandatory.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Request not sent', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function test_all_instances_share_both_ip_budget_keys_in_one_redis_cluster_slot(): void
    {
        $a = (new YClientsRequestThrottle)->keys();
        $this->assertSame($a, (new YClientsRequestThrottle)->keys());
        $this->assertCount(2, array_unique($a));
        foreach ($a as $key) {
            $this->assertStringContainsString('{outbound-ip}', $key);
        }
    }

    #[DataProvider('retryAfterValues')]
    public function test_retry_after_is_published_as_a_shared_duration_or_redis_clock_http_date(?string $header, array $expected): void
    {
        $limiter = $this->limiter([1]);
        $limiter->cooldown($header);
        $this->assertSame([[YClientsRequestThrottle::COOLDOWN_SCRIPT, [$limiter->keys()[1]], $expected]], $limiter->evaluations);
    }

    public static function retryAfterValues(): array
    {
        return [
            'seconds' => ['12', [12000, 0]],
            'long delay is retained' => ['3600', [3600000, 0]],
            'zero remains explicit (Lua retains safe minimum)' => ['0', [0, 0]],
            'HTTP date uses Redis time, not PHP wall clock' => ['Tue, 01 Jan 2030 00:00:00 GMT', [1893456000000, 1]],
            'missing header' => [null, [2000, 0]],
            'relative date is rejected' => ['next week', [2000, 0]],
            'malformed number is rejected' => ['12x', [2000, 0]],
        ];
    }

    public function test_malformed_cooldown_result_is_not_acknowledged(): void
    {
        $this->expectException(RuntimeException::class);
        $this->limiter(['1x'])->cooldown('10');
    }

    private function limiter(array $results): YClientsRequestThrottle
    {
        return new class($results) extends YClientsRequestThrottle
        {
            public array $sleeps = [];
            public array $evaluations = [];
            private int $clock = 0;

            public function __construct(private array $results) {}

            protected function evaluate(string $script, array $keys, array $arguments): mixed
            {
                $this->evaluations[] = [$script, $keys, $arguments];
                if ($this->results === []) {
                    throw new RuntimeException('Unexpected extra Redis evaluation.');
                }
                return array_shift($this->results);
            }

            protected function milliseconds(): int
            {
                return $this->clock;
            }

            protected function sleepMilliseconds(int $milliseconds): void
            {
                $this->sleeps[] = $milliseconds;
                $this->clock += $milliseconds;
            }
        };
    }
}
