<?php

namespace Tests\Unit\YClients;

use App\Console\Commands\YClients\UpdateEntities;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use App\Services\YClients\AmoTransportRetry;
use App\Services\YClients\Leads;
use Exception;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Throwable;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;
use Ufee\Amo\Oauthapi;

class AmoTransportRetryTest extends TestCase
{
    private $previousApplication;
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        $app = new Container();
        $this->logger = new class extends AbstractLogger {
            public array $entries = [];

            public function log($level, $message, array $context = []): void
            {
                $this->entries[] = compact('level', 'message', 'context');
            }
        };
        $app->instance('log', $this->logger);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        parent::tearDown();
    }

    public function test_success_does_not_retry_or_log_an_error(): void
    {
        $attempts = [];
        $result = AmoTransportRetry::run(function ($attempt) use (&$attempts) {
            $attempts[] = $attempt;

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame([1], $attempts);
        $this->assertSame([], $this->logger->entries);
        Sleep::assertNeverSlept();
    }

    public function test_code_zero_retries_twice_with_delays_without_error_logging(): void
    {
        $attempts = [];
        $result = AmoTransportRetry::run(function ($attempt) use (&$attempts) {
            $attempts[] = $attempt;
            if ($attempt < 3) throw $this->transportError();

            return 'updated';
        }, ['record_id' => 123, 'lead_id' => 42]);

        $this->assertSame('updated', $result);
        $this->assertSame([1, 2, 3], $attempts);
        $this->assertSame(['warning', 'warning'], array_column($this->logger->entries, 'level'));
        $this->assertSame(42, $this->logger->entries[0]['context']['lead_id']);
        $this->assertSame(3, $this->logger->entries[1]['context']['next_attempt']);
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(15)->seconds()]);
    }

    public function test_connection_exception_is_retried(): void
    {
        $this->assertSame('updated', AmoTransportRetry::run(function ($attempt) {
            if ($attempt === 1) throw new ConnectionException('Connection reset');

            return 'updated';
        }));
        Sleep::assertSequence([Sleep::for(5)->seconds()]);
    }

    public function test_exhausted_transport_error_is_not_hidden(): void
    {
        $exception = $this->transportError();
        $attempts = 0;
        try {
            AmoTransportRetry::run(function () use ($exception, &$attempts) {
                $attempts++;
                throw $exception;
            });
            $this->fail('Expected the original error after three attempts.');
        } catch (Exception $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(3, $attempts);
        $this->assertSame(['warning', 'warning'], array_column($this->logger->entries, 'level'));
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(15)->seconds()]);
    }

    #[DataProvider('permanentErrors')]
    public function test_other_errors_are_not_retried(Throwable $exception): void
    {
        $attempts = 0;
        try {
            AmoTransportRetry::run(function () use ($exception, &$attempts) {
                $attempts++;
                throw $exception;
            });
            $this->fail('Expected the original error immediately.');
        } catch (Throwable $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(1, $attempts);
        $this->assertSame([], $this->logger->entries);
        Sleep::assertNeverSlept();
    }

    public static function permanentErrors(): array
    {
        return [
            'forbidden' => [new Exception('Invalid API response (non JSON), code: 403', 403)],
            'rate limit' => [new Exception('Invalid API response (non JSON), code: 429', 429)],
            'invalid JSON with HTTP response' => [new Exception('Invalid API response (non JSON), code: 200')],
            'validation with default exception code' => [new Exception('Invalid value for custom field', 0)],
            'programming error' => [new \TypeError('Unexpected return type')],
        ];
    }

    public function test_lead_update_reloads_same_lead_and_retries_save(): void
    {
        $fresh = $this->lead(42);
        $fresh->expects($this->once())->method('save');
        $service = new class($fresh) {
            public array $requestedIds = [];
            public function __construct(private Lead $lead) {}
            public function find($id): Lead
            {
                $this->requestedIds[] = $id;

                return $this->lead;
            }
        };
        $stale = $this->lead(42, $service);
        $stale->expects($this->once())->method('save')->willThrowException($this->transportError());

        $this->assertSame($fresh, Leads::update($stale, $this->mapping(), $this->record(), 55));
        $this->assertSame([42], $service->requestedIds);
        Sleep::assertSequence([Sleep::for(5)->seconds()]);
    }

    public function test_missing_lead_on_retry_does_not_create_replacement(): void
    {
        $service = new class {
            public function find($id): null { return null; }
        };
        $lead = $this->lead(42, $service);
        $lead->expects($this->once())->method('save')->willThrowException($this->transportError());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Existing amoCRM lead not found while retrying');
        Leads::update($lead, $this->mapping(), $this->record());
    }

    public function test_unknown_lead_creation_outcome_is_not_retried(): void
    {
        $lead = $this->lead(0);
        $lead->expects($this->once())->method('save')->willThrowException($this->transportError());
        $contact = $this->createMock(Contact::class);
        $contact->expects($this->once())->method('createLead')->willReturn($lead);

        try {
            Leads::create($contact, $this->mapping(), $this->record());
            $this->fail('Unknown creation result must not be retried.');
        } catch (Exception $exception) {
            $this->assertSame($this->transportError()->getMessage(), $exception->getMessage());
        }
        Sleep::assertNeverSlept();
    }

    #[DataProvider('mappedEntities')]
    public function test_mapped_fields_reload_entity_before_transport_retry(string $model, string $serviceName, string $method, string $fields, ?int $contactId): void
    {
        $first = $this->createMock($model);
        $fresh = $this->createMock($model);
        $service = new class([$first, $fresh]) {
            public int $reads = 0;
            public function __construct(private array $entities) {}
            public function find($id): Lead|Contact { return $this->entities[$this->reads++]; }
        };
        $api = $this->createMock(Oauthapi::class);
        $api->expects($this->exactly(2))->method('__call')->with($serviceName, [])->willReturn($service);
        $client = $this->createMock(Client::class);
        $client->service = $api;
        $setting = $this->getMockBuilder(Setting::class)->onlyMethods([$method])->getMock();
        $setting->{$fields} = '[{"field_amo":123,"field_yc":"record_id"}]';
        $seen = [];
        $setting->expects($this->exactly(2))->method($method)->willReturnCallback(function ($entity) use (&$seen) {
            $seen[] = $entity;
            if (count($seen) === 1) throw $this->transportError();

            return $entity;
        });
        $record = $this->getMockBuilder(Record::class)->onlyMethods(['isLeadOwnedByAnotherYClientsRecord'])->getMock();
        $record->forceFill(['id' => 1, 'record_id' => 123, 'lead_id' => 42, 'account_id' => 124, 'setting_id' => 14]);
        $record->method('isLeadOwnedByAnotherYClientsRecord')->willReturn(false);

        (new ReflectionMethod(UpdateEntities::class, 'updateAmoEntitiesWithRetry'))->invoke(
            new UpdateEntities(), $client, $setting, $record, $contactId, ['record_id' => 123],
        );

        $this->assertSame([$first, $fresh], $seen);
        $this->assertSame(2, $service->reads);
        Sleep::assertSequence([Sleep::for(5)->seconds()]);
    }

    public static function mappedEntities(): array
    {
        return [
            'lead' => [Lead::class, 'leads', 'YCSetLeadFields', 'fields_lead', null],
            'contact' => [Contact::class, 'contacts', 'YCSetContactFields', 'fields_contact', 77],
        ];
    }

    private function lead(int $id, ?object $service = null): Lead
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('__get')->willReturnMap([['id', $id], ['service', $service]]);

        return $lead;
    }

    private function record(): Record
    {
        return (new Record())->forceFill(['id' => 1, 'record_id' => 123, 'account_id' => 124, 'setting_id' => 14, 'cost' => 24000]);
    }

    private function mapping(): object
    {
        return (object)['status_id' => 142, 'pipeline_id' => 9955494];
    }

    private function transportError(): Exception
    {
        return new Exception('Invalid API response (non JSON), code: 0');
    }
}
