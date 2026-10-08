<?php

namespace Tests\Unit\AmoCrm;

use App\Models\Core\Account;
use App\Services\amoCRM\AmoCrmRequestThrottle;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalThrottleRedis;

/** Run explicitly against a disposable local Redis, never the app's configured Redis. */
class AmoCrmRequestThrottleRedisTest extends TestCase
{
    private string $socketPath;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->socketPath = (string) getenv('AMOCRM_THROTTLE_TEST_REDIS_SOCKET');
        if ($this->socketPath === '' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires explicit disposable Redis Unix socket and pcntl.');
        }
        $container = new Container;
        $container->instance('config', new Repository);
        Container::setInstance($container);
        $this->account = (new Account)->forceFill([
            'subdomain' => 'throttle-test-'.bin2hex(random_bytes(8)),
            'zone' => 'ru', 'client_id' => 'integration-a',
        ]);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_eight_processes_share_one_budget_for_two_hundred_requests(): void
    {
        $key = 'throttle-test:admissions:'.bin2hex(random_bytes(8));
        $pids = [];
        for ($worker = 0; $worker < 8; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $redis = new LocalThrottleRedis($this->socketPath);
                    $limiter = new SocketThrottle($redis);
                    $account = clone $this->account;
                    $account->id = $worker + 1;
                    for ($i = 0; $i < 25; $i++) {
                        $limiter->acquire($account);
                        // Record the timestamp stored atomically by the actual admission script.
                        $time = $redis->command('GET', $limiter->keys($account)[0]);
                        $redis->command('RPUSH', $key, (int) $time - 200);
                        $redis->command('EXPIRE', $key, 120);
                    }
                    exit(0);
                } catch (\Throwable $error) {
                    fwrite(STDERR, $error->getMessage()."\n");
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        $statuses = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
        }
        $this->assertSame(array_fill(0, 8, 0), $statuses);
        $redis = new LocalThrottleRedis($this->socketPath);
        $times = array_map('intval', $redis->command('LRANGE', $key, 0, -1));
        sort($times);
        $this->assertCount(200, $times);
        for ($i = 1; $i < count($times); $i++) {
            $this->assertGreaterThanOrEqual(200, $times[$i] - $times[$i - 1]);
        }
        $this->assertGreaterThanOrEqual(39800, end($times) - $times[0]);
        fwrite(STDOUT, '\nRedis concurrency: 200 admissions, 8 workers, '.round((end($times) - $times[0]) / 1000, 3)." s, no dropped requests.\n");
        $redis->command('DEL', $key);
    }

    public function test_real_lua_shares_cooldown_and_never_shortens_it(): void
    {
        $redis = new LocalThrottleRedis($this->socketPath);
        $limiter = new SocketThrottle($redis);
        $limiter->cooldown($this->account, '120');
        $key = $limiter->keys($this->account)[2];
        $before = (int) $redis->command('GET', $key);
        $limiter->cooldown($this->account, '2');
        $this->assertSame($before, (int) $redis->command('GET', $key));
        $this->assertGreaterThan(119000, $redis->command('PTTL', $key));
        $other = clone $this->account;
        $other->client_id = 'integration-b';
        $this->expectExceptionMessage('Время безопасного ожидания');
        try {
            $limiter->acquire($other);
        } finally {
            $redis->command('DEL', $key);
        }
    }

    public function test_real_lua_keeps_other_accounts_independent_and_applies_aggregate_interval(): void
    {
        $redis = new LocalThrottleRedis($this->socketPath);
        $limiter = new SocketThrottle($redis);
        $limiter->acquire($this->account);
        $other = clone $this->account;
        $other->client_id = 'integration-b';
        $wait = $redis->command('EVAL', AmoCrmRequestThrottle::ACQUIRE_SCRIPT, 3, ...[...$limiter->keys($other), 200, 25]);
        $this->assertGreaterThan(0, $wait);
        $this->assertLessThanOrEqual(25, $wait);
        $other->subdomain = 'independent-'.bin2hex(random_bytes(8));
        $this->assertSame(0, $redis->command('EVAL', AmoCrmRequestThrottle::ACQUIRE_SCRIPT, 3, ...[...$limiter->keys($other), 200, 25]));
    }
}

class SocketThrottle extends AmoCrmRequestThrottle
{
    public function __construct(private LocalThrottleRedis $redis) {}

    protected function evaluate(string $script, array $keys, array $arguments): mixed
    {
        return $this->redis->command('EVAL', $script, count($keys), ...array_merge($keys, $arguments));
    }
}
