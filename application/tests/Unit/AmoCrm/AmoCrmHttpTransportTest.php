<?php

namespace Tests\Unit\AmoCrm;

use App\Models\Core\Account;
use App\Services\amoCRM\AmoCrmHttpTransport;
use App\Services\amoCRM\AmoCrmRequestThrottle;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Companies;
use App\Services\amoCRM\Models\Contacts;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmoCrmHttpTransportTest extends TestCase
{
    private mixed $previousContainer;

    private mixed $previousFacade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $container = new Container;
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_post_retries_only_the_rejected_request_after_shared_cooldown(): void
    {
        $account = $this->account();
        $events = [];
        $throttle = $this->createMock(AmoCrmRequestThrottle::class);
        $throttle->expects($this->exactly(2))->method('acquire')->with($account)
            ->willReturnCallback(function () use (&$events): void {
                $events[] = 'acquire';
            });
        $throttle->expects($this->once())->method('cooldown')->with($account, '2', 1)
            ->willReturnCallback(function () use (&$events): void {
                $events[] = 'cooldown';
            });
        $responses = Http::sequence()->push([], 429, ['Retry-After' => '2'])->push(['id' => 123], 201);
        Http::fake(function ($request, $options) use (&$events, $responses) {
            $events[] = 'send';

            return $responses($request, $options);
        });

        $response = (new AmoCrmHttpTransport($throttle))->send($account,
            fn () => Http::post('https://throttle.test/api/v4/leads', [['name' => 'One lead']]));

        $this->assertSame(201, $response->status());
        $this->assertSame(['acquire', 'send', 'cooldown', 'acquire', 'send'], $events);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('POST', $request->method());
            $this->assertSame([['name' => 'One lead']], $request->data());
        }
    }

    public function test_final_429_also_updates_shared_cooldown_and_stops_after_three_sends(): void
    {
        $account = $this->account();
        $attempts = [];
        $throttle = $this->createMock(AmoCrmRequestThrottle::class);
        $throttle->expects($this->exactly(3))->method('acquire')->with($account);
        $throttle->expects($this->exactly(3))->method('cooldown')->with($account, '7', $this->anything())
            ->willReturnCallback(function ($account, $retryAfter, $attempt) use (&$attempts): void {
                $attempts[] = $attempt;
            });
        Http::fake(['https://throttle.test/*' => Http::response([], 429, ['Retry-After' => '7'])]);

        $response = (new AmoCrmHttpTransport($throttle))->send($account,
            fn () => Http::post('https://throttle.test/api/v4/leads', [['name' => 'One lead']]));

        $this->assertSame(429, $response->status());
        $this->assertSame([1, 2, 3], $attempts);
        Http::assertSentCount(3);
    }

    #[DataProvider('otherStatuses')]
    public function test_other_http_failures_are_returned_without_retries(int $status): void
    {
        $account = $this->account();
        $throttle = $this->createMock(AmoCrmRequestThrottle::class);
        $throttle->expects($this->once())->method('acquire')->with($account);
        $throttle->expects($this->never())->method('cooldown');
        Http::fake(['https://throttle.test/*' => Http::response([], $status)]);

        $response = (new AmoCrmHttpTransport($throttle))->send($account,
            fn () => Http::post('https://throttle.test/api/v4/leads', [['name' => 'One lead']]));

        $this->assertSame($status, $response->status());
        Http::assertSentCount(1);
    }

    public static function otherStatuses(): array
    {
        return array_map(fn ($status) => [$status], [400, 401, 403, 408, 500, 502, 503]);
    }

    public function test_ambiguous_timeout_propagates_without_retrying_a_post(): void
    {
        $account = $this->account();
        $calls = 0;
        $error = new ConnectionException('Synthetic timeout');
        $throttle = $this->createMock(AmoCrmRequestThrottle::class);
        $throttle->expects($this->once())->method('acquire')->with($account);
        $throttle->expects($this->never())->method('cooldown');
        Http::fake(function () use (&$calls, $error) {
            $calls++;
            throw $error;
        });

        try {
            (new AmoCrmHttpTransport($throttle))->send($account,
                fn () => Http::post('https://throttle.test/api/v4/leads', [['name' => 'One lead']]));
            $this->fail('Timeout must propagate.');
        } catch (ConnectionException $actual) {
            $this->assertSame($error, $actual);
            $this->assertSame(1, $calls);
        }
    }

    public function test_client_v4_and_workflow_requests_use_the_shared_transport(): void
    {
        $account = $this->account();
        $throttle = $this->createMock(AmoCrmRequestThrottle::class);
        $throttle->expects($this->exactly(4))->method('acquire')->with($account);
        $throttle->expects($this->exactly(2))->method('cooldown')->with($account, '1', 1);
        app()->instance(AmoCrmHttpTransport::class, new AmoCrmHttpTransport($throttle));
        Http::fake(['https://example.amocrm.ru/*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '1'])->push(['id' => 123], 201)
            ->push([], 429, ['Retry-After' => '1'])->push(['id' => 456], 201)]);

        $client = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $client->account = $account;
        $response = (new \ReflectionMethod($client, 'sendV4Request'))->invoke($client, 'POST', '/api/v4/leads', [['name' => 'Client']], []);
        $this->assertSame(123, $response->json('id'));

        $executor = app(WorkflowAmoCrmActionExecutor::class);
        (new \ReflectionProperty($executor, 'captureAmoExchange'))->setValue($executor, true);
        $body = (new \ReflectionMethod($executor, 'amoRequest'))->invoke($executor, $account, 'POST', '/api/v4/leads', [['name' => 'Workflow']]);
        $result = (new \ReflectionMethod($executor, 'withAmoExchange'))->invoke($executor, ['success' => true], null);
        $this->assertSame(456, $body['id']);
        $this->assertSame([429, 201], array_column(array_column($result['output']['amo_exchange'], 'response'), 'code'));
        Http::assertSentCount(4);
    }

    public function test_com_phone_and_email_lookups_use_the_shared_transport(): void
    {
        $account = $this->account();
        $account->zone = 'com';
        $throttle = $this->createMock(AmoCrmRequestThrottle::class);
        $throttle->expects($this->exactly(4))->method('acquire')->with($account);
        $throttle->expects($this->never())->method('cooldown');
        app()->instance(AmoCrmHttpTransport::class, new AmoCrmHttpTransport($throttle));
        Http::fake(['https://example.amocrm.com/*' => Http::response(['_embedded' => ['contacts' => [], 'companies' => []]])]);
        $client = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $client->account = $account;
        $fields = ['Телефоны' => ['79991234567'], 'Почта' => 'synthetic@example.test'];

        $this->assertNull(Contacts::searchCom($fields, $client));
        $this->assertNull(Companies::searchCom($fields, $client));

        Http::assertSentCount(4);
        foreach (['contacts', 'companies'] as $entity) {
            $this->assertCount(2, Http::recorded(fn ($request) => $request->method() === 'GET'
                && str_contains($request->url(), '/api/v4/'.$entity.'?query=')));
        }
    }

    private function account(): Account
    {
        return (new Account)->forceFill([
            'id' => 1, 'subdomain' => 'example', 'zone' => 'ru',
            'client_id' => 'test-integration', 'access_token' => 'synthetic-only',
        ]);
    }
}
