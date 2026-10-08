<?php

namespace Tests\Unit\AmoCrm;

use App\Models\Core\Account;
use App\Services\amoCRM\AmoCrmRequestThrottle;
use App\Services\amoCRM\EloquentStorage;
use App\Services\amoCRM\IsolatedOauthClient;
use Illuminate\Container\Container;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ufee\Amo\Api\Oauth\Query;
use Ufee\Amo\Oauthapi;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SdkRequestThrottleTest extends TestCase
{
    private Container $previousContainer;

    private object $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $this->throttle = new class
        {
            public array $events = [];

            public function acquire(Account $account): void
            {
                $this->events[] = ['acquire', $account->getKey()];
            }

            public function cooldown(Account $account, ?string $retryAfter = null, int $attempt = 1): void
            {
                $this->events[] = ['cooldown', $account->getKey(), $retryAfter, $attempt];
            }
        };
        $container->instance(AmoCrmRequestThrottle::class, $this->throttle);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Sleep::fake(false);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_sdk_reacquires_after_explicit_429_before_retrying_the_same_post(): void
    {
        $client = $this->client();
        $query = $this->scriptedQuery($client, [429, 200]);
        $query->setMethod('POST')->setPostData(['name' => 'one write']);
        $client->queries->onResponseCode(429, function (): void {
            $this->assertSame('cooldown', $this->throttle->events[1][0]);
        });

        $query->execute();

        $this->assertSame(2, $query->sends);
        $this->assertSame([
            ['acquire', 68],
            ['cooldown', 68, null, 1],
            ['acquire', 68],
        ], $this->throttle->events);
        $this->assertSame([['name' => 'one write'], ['name' => 'one write']], $query->payloads);
    }

    public function test_sdk_publishes_the_final_cooldown_and_stops_after_three_rejected_sends(): void
    {
        $client = $this->client();
        $query = $this->scriptedQuery($client, [429, 429, 429, 200]);

        try {
            $query->execute();
            $this->fail('Expected the explicit rate limit error.');
        } catch (RuntimeException $exception) {
            $this->assertSame(429, $exception->getCode());
        }

        $this->assertSame(3, $query->sends);
        $this->assertSame([
            ['acquire', 68], ['cooldown', 68, null, 1],
            ['acquire', 68], ['cooldown', 68, null, 2],
            ['acquire', 68], ['cooldown', 68, null, 3],
        ], $this->throttle->events);
    }

    #[DataProvider('ambiguousOrPermanentResponses')]
    public function test_sdk_does_not_replay_a_write_without_an_explicit_rate_limit(int $status): void
    {
        $client = $this->client();
        $query = $this->scriptedQuery($client, [$status, 200]);
        $query->setMethod('POST');

        $query->execute();

        $this->assertSame(1, $query->sends);
        $this->assertSame([['acquire', 68]], $this->throttle->events);
        $this->assertSame($status, $query->response->getCode());
    }

    public static function ambiguousOrPermanentResponses(): array
    {
        return [
            'no HTTP response' => [0],
            'bad gateway' => [502],
            'gateway timeout' => [504],
            'validation' => [400],
            'server error' => [500],
        ];
    }

    public function test_explicit_local_delay_runs_before_shared_admission(): void
    {
        $client = $this->client();
        $query = new Query($client);
        $query->setStartTime(microtime(true));
        $client->queries->push($query);
        $client->queries->setDelay(2);

        $this->assertSame(0, $client->queries->getDelay());

        Sleep::assertSleptTimes(1);
        $this->assertSame([['acquire', 68]], $this->throttle->events);
    }

    public function test_initial_token_exchange_acquires_without_replaying_a_rejected_exchange(): void
    {
        $client = $this->client();
        $this->mockTokenExchange(429, (object) ['hint' => 'rate limited']);

        try {
            $client->fetchAccessToken('test-code');
            $this->fail('Expected the SDK exchange error.');
        } catch (\Exception $exception) {
            $this->assertSame(429, $exception->getCode());
        }

        $this->assertSame([['acquire', 68]], $this->throttle->events);
    }

    public function test_refresh_preserves_error_callbacks_without_replaying_the_exchange(): void
    {
        $client = $this->client();
        $this->mockTokenExchange(429, (object) ['hint' => 'rate limited']);
        $called = 0;
        $client->onAccessTokenRefreshError(function ($exception, $query, $response) use (&$called): void {
            $called++;
            $this->assertSame(429, $exception->getCode());
            $this->assertSame(429, $response->getCode());
            $this->assertNotNull($query);
        });

        $this->assertNull($client->refreshAccessToken());

        $this->assertSame(1, $called);
        $this->assertSame([['acquire', 68]], $this->throttle->events);
    }

    public function test_refresh_preserves_success_callback_and_token_storage(): void
    {
        $client = $this->client();
        $this->mockTokenExchange(200, (object) [
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 86400,
            'token_type' => 'Bearer',
        ]);
        $called = 0;
        $client->onAccessTokenRefresh(function ($oauth) use (&$called): void {
            $called++;
            $this->assertSame('new-access', $oauth['access_token']);
        });

        $oauth = $client->refreshAccessToken();

        $this->assertSame(1, $called);
        $this->assertSame('new-access', $oauth['access_token']);
        $this->assertSame('new-access', $client->getOauth('access_token'));
        $this->assertSame([['acquire', 68]], $this->throttle->events);
    }

    private function scriptedQuery(IsolatedOauthClient $client, array $codes): Query
    {
        // Exercise the real SDK execute/retry loop while replacing only HTTP I/O.
        $query = new class($client) extends Query
        {
            public array $codes = [];

            public int $status = 0;

            public int $sends = 0;

            public array $payloads = [];

            public function get()
            {
                $this->sends++;
                $this->status = array_shift($this->codes);
                $this->payloads[] = $this->post_data;

                return '{}';
            }

            public function post()
            {
                return $this->get();
            }
        };
        $query->codes = $codes;
        $response = Mockery::mock('overload:Ufee\\Amo\\Api\\Response');
        $response->shouldReceive('getCode')->andReturnUsing(fn () => $query->status);
        $response->shouldReceive('getData')->andReturn('{}');

        return $query;
    }

    private function mockTokenExchange(int $status, object $payload): void
    {
        $query = Mockery::mock('overload:Ufee\\Amo\\Api\\Oauth\\Query');
        $query->shouldReceive('setUrl')->once()->with('/oauth2/access_token')->andReturnSelf();
        $query->shouldReceive('setPostData')->andReturnSelf();
        $query->shouldReceive('setStartTime')->zeroOrMoreTimes();
        $query->shouldReceive('setEndTime')->zeroOrMoreTimes();
        $query->shouldReceive('post')->once()->andReturn('{}');
        $response = Mockery::mock('overload:Ufee\\Amo\\Api\\Response');
        $response->shouldReceive('getCode')->andReturn($status);
        $response->shouldReceive('parseJson')->andReturn($payload);
    }

    private function client(): IsolatedOauthClient
    {
        $account = new Account;
        $account->forceFill([
            'id' => 68,
            'subdomain' => 'sdk-throttle-test',
            'client_id' => 'sdk-throttle-client',
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'expires_in' => 86400,
            'created_at' => time(),
            'zone' => 'ru',
        ]);
        $options = [
            'domain' => $account->subdomain,
            'client_id' => $account->client_id,
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://example.invalid/oauth',
            'zone' => 'ru',
        ];
        $storage = new class($options, $account) extends EloquentStorage
        {
            public function setOauthData(Oauthapi $client, array $oauth): bool
            {
                $this->model->forceFill($oauth);

                return true;
            }
        };

        return IsolatedOauthClient::forAccount($options, $storage);
    }
}
