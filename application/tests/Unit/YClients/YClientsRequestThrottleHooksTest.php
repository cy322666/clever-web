<?php
namespace Tests\Unit\YClients;

use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\YClients;
use App\Services\YClients\YClientsRequestThrottle;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class YClientsRequestThrottleHooksTest extends TestCase
{
    private Container $previousContainer;
    private mixed $previousFacadeApplication;
    private mixed $previousResolvedFacades;
    private object $throttle;
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolvedFacades = (new \ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        $container = new Container;
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository(['services' => ['yclients' => ['api_url' => 'https://yclients.example.invalid/api/v1']]]));
        $container->instance('log', new NullLogger);
        $container->instance(Factory::class, new Factory);
        $events = &$this->events;
        $this->throttle = new class($events) extends YClientsRequestThrottle {
            private array $events;
            public bool $failAcquire = false;
            public bool $failCooldown = false;
            public function __construct(array &$events) { $this->events = &$events; }
            public function acquire(): void { $this->events[] = ['acquire']; if ($this->failAcquire) throw new RuntimeException('fixture_admission_unavailable'); }
            public function cooldown(?string $retryAfter): void { $this->events[] = ['cooldown', $retryAfter]; if ($this->failCooldown) throw new RuntimeException('fixture_cooldown_unavailable'); }
        };
        $container->instance(YClientsRequestThrottle::class, $this->throttle);
        Sleep::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        (new \ReflectionProperty(Facade::class, 'resolvedInstance'))->setValue(null, $this->previousResolvedFacades);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function client(): YClients
    {
        return new YClients(new Setting(['user_token' => 'fixture-user', 'partner_token' => 'fixture-partner', 'fields_lead' => '[]']));
    }

    private function fake(callable $reply): void
    {
        Http::fake(['https://yclients.example.invalid/*' => function (Request $request) use ($reply) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('https://yclients.example.invalid/api/v1/record/10/20', $request->url());
            $this->events[] = ['send'];
            return $reply($request);
        }]);
    }

    public function test_success_is_admitted_before_the_real_laravel_http_pipeline(): void
    {
        $this->fake(fn () => Http::response(['success' => true, 'data' => ['id' => 20]], 200));
        $this->assertSame(20, $this->client()->getRecord('10', '20')->data->id);
        $this->assertSame([['acquire'], ['send']], $this->events);
        Http::assertSentCount(1);
    }

    public function test_every_connection_retry_reenters_admission_before_sending(): void
    {
        $attempts = 0;
        $this->fake(function (Request $request) use (&$attempts) {
            if (++$attempts < 3) return (Http::failedConnection('fixture_connection'))($request);
            return Http::response(['success' => true, 'data' => ['id' => 20]], 200);
        });
        $this->assertSame(20, $this->client()->getRecord('10', '20')->data->id);
        $this->assertSame([['acquire'], ['send'], ['acquire'], ['send'], ['acquire'], ['send']], $this->events);
        $this->assertSame(3, $attempts);
    }

    public function test_exhausted_connection_failure_preserves_five_attempt_limit(): void
    {
        $this->fake(fn (Request $request) => (Http::failedConnection('fixture_connection'))($request));
        try { $this->client()->getRecord('10', '20'); $this->fail('Expected connection failure.'); }
        catch (ConnectionException $error) { $this->assertStringContainsString('fixture_connection', $error->getMessage()); }
        $this->assertCount(10, $this->events);
        $this->assertSame(array_merge(...array_fill(0, 5, [['acquire'], ['send']])), $this->events);
    }

    public function test_explicit_429_publishes_cooldown_without_adding_http_retry(): void
    {
        $this->fake(fn () => Http::response(['error' => 'original_rate_limit'], 429, ['Retry-After' => '8']));
        try { $this->client()->getRecord('10', '20'); $this->fail('Expected original429.'); }
        catch (RequestException $error) { $this->assertSame(429, $error->response->status()); $this->assertSame('original_rate_limit', $error->response->json('error')); }
        $this->assertSame([['acquire'], ['send'], ['cooldown', '8']], $this->events);
        Http::assertSentCount(1);
    }

    public function test_cooldown_storage_failure_does_not_replace_original_429_or_retry(): void
    {
        $this->throttle->failCooldown = true;
        $this->fake(fn () => Http::response(['error' => 'original_rate_limit'], 429, ['Retry-After' => '8']));
        try { $this->client()->getRecord('10', '20'); $this->fail('Expected original429.'); }
        catch (RequestException $error) { $this->assertSame(429, $error->response->status()); $this->assertSame('original_rate_limit', $error->response->json('error')); }
        $this->assertSame([['acquire'], ['send'], ['cooldown', '8']], $this->events);
        Http::assertSentCount(1);
    }

    public function test_admission_failure_is_fail_closed_before_any_http_request(): void
    {
        $this->throttle->failAcquire = true;
        $this->fake(fn () => Http::response(['success' => true], 200));
        try { $this->client()->getRecord('10', '20'); $this->fail('Expected admission failure.'); }
        catch (RuntimeException $error) { $this->assertSame('fixture_admission_unavailable', $error->getMessage()); }
        $this->assertSame([['acquire']], $this->events);
        Http::assertNothingSent();
    }

    public function test_server_error_does_not_gain_new_retry_or_cooldown(): void
    {
        $this->fake(fn () => Http::response(['error' => 'original_server_failure'], 503));
        try { $this->client()->getRecord('10', '20'); $this->fail('Expected original503.'); }
        catch (RequestException $error) { $this->assertSame(503, $error->response->status()); }
        $this->assertSame([['acquire'], ['send']], $this->events);
        Http::assertSentCount(1);
    }
}
