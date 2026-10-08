<?php

namespace Tests\Unit\AmoCrm;

use App\Models\Core\Account;
use App\Services\amoCRM\AmoCrmRequestThrottle;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AmoCrmRequestThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        $container->instance('config', new Repository(['amocrm-throttle' => require __DIR__.'/../../../config/amocrm-throttle.php']));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_local_rows_tokens_and_widget_labels_do_not_split_one_integration_budget(): void
    {
        $limiter = new AmoCrmRequestThrottle;
        $first = $this->account(['id' => 1, 'widget' => 'workflows', 'access_token' => 'old']);
        $second = $this->account(['id' => 2, 'widget' => 'another-label', 'access_token' => 'rotated']);
        $this->assertSame($limiter->keys($first), $limiter->keys($second));
        $this->assertStringNotContainsString('customer', implode('', $limiter->keys($first)));
        $this->assertStringNotContainsString('client-a', implode('', $limiter->keys($first)));
    }

    public function test_different_widgets_share_account_budget_but_have_separate_integration_budget(): void
    {
        $limiter = new AmoCrmRequestThrottle;
        $a = $limiter->keys($this->account());
        $b = $limiter->keys($this->account(['client_id' => 'client-b']));
        $c = $limiter->keys($this->account(['subdomain' => 'another']));
        $this->assertNotSame($a[0], $b[0]);
        $this->assertSame(array_slice($a, 1), array_slice($b, 1));
        $this->assertNotSame($a[1], $c[1]);
        preg_match('/\{([^}]+)\}/', $a[0], $tag);
        foreach ($a as $key) {
            $this->assertStringContainsString($tag[0], $key);
        }
    }

    public function test_domain_aliases_and_case_resolve_to_same_budget(): void
    {
        $limiter = new AmoCrmRequestThrottle;
        $a = $limiter->keys($this->account(['zone' => 'com']));
        $b = $limiter->keys($this->account(['zone' => 'com', 'endpoint' => 'https://CUSTOMER.kommo.com./', 'client_id' => 'CLIENT-A']));
        $c = $limiter->keys($this->account(['zone' => 'amocrm.com']));
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->assertNotSame($a, $limiter->keys($this->account(['zone' => 'ru'])));
    }

    public function test_missing_integration_uses_shared_conservative_bucket(): void
    {
        $limiter = new AmoCrmRequestThrottle;
        $this->assertSame(
            $limiter->keys($this->account(['id' => 1, 'client_id' => null])),
            $limiter->keys($this->account(['id' => 2, 'client_id' => ''])),
        );
    }

    public function test_endpoint_mismatch_cannot_split_sdk_and_http_budgets(): void
    {
        $this->expectExceptionMessage('Адрес подключения amoCRM не совпадает');
        (new AmoCrmRequestThrottle)->keys($this->account(['endpoint' => 'https://wrong.amocrm.ru']));
    }

    public function test_missing_account_identity_fails_before_redis_or_http(): void
    {
        $limiter = new ClockedThrottle;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Не определён аккаунт');
        try {
            $limiter->acquire(new Account);
        } finally {
            $this->assertSame([], $limiter->calls);
        }
    }

    public function test_two_hundred_admissions_are_paced_without_dropping_requests(): void
    {
        $limiter = new ClockedThrottle;
        $times = [];
        for ($i = 0; $i < 200; $i++) {
            $limiter->acquire($this->account(['id' => $i]));
            $times[] = $limiter->now;
        }
        $this->assertCount(200, $times);
        $this->assertSame(39800, $times[199] - $times[0]);
        for ($i = 1; $i < 200; $i++) {
            $this->assertGreaterThanOrEqual(200, $times[$i] - $times[$i - 1]);
        }
    }

    public function test_account_aggregate_budget_applies_across_integrations(): void
    {
        $limiter = new ClockedThrottle;
        $limiter->acquire($this->account());
        $limiter->acquire($this->account(['client_id' => 'client-b']));
        $this->assertSame(25, $limiter->now);
        $limiter->acquire($this->account(['subdomain' => 'another']));
        $this->assertSame(25, $limiter->now);
    }

    public function test_cooldown_published_during_sleep_is_rechecked_before_admission(): void
    {
        $limiter = new ClockedThrottle;
        $account = $this->account();
        $limiter->acquire($account);
        $limiter->duringNextSleep = fn () => $limiter->cooldown($account, '5');
        $limiter->acquire($account);
        $this->assertSame(5200, $limiter->now);
        $this->assertSame([200, 5000], $limiter->sleeps);
    }

    public function test_shorter_cooldown_does_not_shorten_existing_pause(): void
    {
        $limiter = new ClockedThrottle;
        $account = $this->account();
        $limiter->cooldown($account, '10');
        $limiter->now = 1000;
        $limiter->cooldown($account, '2');
        $limiter->acquire($this->account(['client_id' => 'client-b']));
        $this->assertSame(10000, $limiter->now);
    }

    public function test_long_retry_after_is_retained_but_caller_wait_is_bounded(): void
    {
        $limiter = new ClockedThrottle;
        $account = $this->account();
        $limiter->cooldown($account, '120');
        $this->expectExceptionMessage('Время безопасного ожидания');
        try {
            $limiter->acquire($account);
        } finally {
            $this->assertSame(120000, $limiter->state[$limiter->keys($account)[2]]);
            $this->assertSame([], $limiter->sleeps);
        }
    }

    public function test_retry_after_http_date_and_fallback_backoff(): void
    {
        $limiter = new ClockedThrottle;
        $account = $this->account();
        $limiter->cooldown($account, gmdate('D, d M Y H:i:s \G\M\T', $limiter->wall + 12));
        $this->assertSame(12000, $limiter->state[$limiter->keys($account)[2]]);
        $other = $this->account(['subdomain' => 'fallback']);
        $limiter->cooldown($other, 'tomorrow', 2);
        $this->assertSame(4000, $limiter->state[$limiter->keys($other)[2]]);
        $limiter->cooldown($other, null, 3);
        $this->assertSame(8000, $limiter->state[$limiter->keys($other)[2]]);
        $invalidDate = $this->account(['subdomain' => 'invalid-date']);
        $limiter->cooldown($invalidDate, 'Sun, 32 Oct 2026 00:00:00 GMT');
        $this->assertSame(2000, $limiter->state[$limiter->keys($invalidDate)[2]]);
    }

    public function test_invalid_redis_response_fails_closed(): void
    {
        $limiter = new class extends ClockedThrottle
        {
            protected function evaluate(string $script, array $keys, array $arguments): mixed
            {
                return null;
            }
        };
        $this->expectExceptionMessage('Не удалось проверить общий лимит');
        $limiter->acquire($this->account());
    }

    public function test_redis_outage_is_not_a_per_worker_fallback_and_does_not_expose_credentials(): void
    {
        $redis = \Mockery::mock();
        $redis->shouldReceive('connection')->once()->andThrow(new RuntimeException('redis://private:password@example'));
        Redis::swap($redis);
        $this->expectExceptionMessage('Общий ограничитель запросов amoCRM недоступен');
        (new AmoCrmRequestThrottle)->acquire($this->account());
    }

    private function account(array $attributes = []): Account
    {
        return (new Account)->forceFill(array_replace([
            'subdomain' => 'customer', 'zone' => 'ru', 'client_id' => 'client-a',
        ], $attributes));
    }
}

class ClockedThrottle extends AmoCrmRequestThrottle
{
    public int $now = 0;

    public int $wall = 1800000000;

    public array $state = [];

    public array $calls = [];

    public array $sleeps = [];

    public mixed $duringNextSleep = null;

    protected function evaluate(string $script, array $keys, array $arguments): mixed
    {
        $this->calls[] = [$script, $keys, $arguments];
        if ($script === self::COOLDOWN_SCRIPT) {
            $this->state[$keys[0]] = max($this->state[$keys[0]] ?? 0, $this->now + $arguments[0]);

            return 1;
        }
        $ready = max(array_map(fn ($key) => $this->state[$key] ?? 0, $keys));
        if ($ready > $this->now) {
            return $ready - $this->now;
        }
        $this->state[$keys[0]] = $this->now + $arguments[0];
        $this->state[$keys[1]] = $this->now + $arguments[1];

        return 0;
    }

    protected function monotonicMilliseconds(): int
    {
        return $this->now;
    }

    protected function wallTimeSeconds(): int
    {
        return $this->wall;
    }

    protected function sleepMilliseconds(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        $this->now += $milliseconds;
        if ($this->duringNextSleep !== null) {
            $callback = $this->duringNextSleep;
            $this->duringNextSleep = null;
            $callback();
        }
    }
}
