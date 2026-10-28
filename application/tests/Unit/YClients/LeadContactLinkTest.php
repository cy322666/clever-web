<?php

namespace Tests\Unit\YClients;

use App\Services\amoCRM\Client;
use App\Services\YClients\Leads;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Throwable;

class LeadContactLinkTest extends TestCase
{
    private $previousApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        $app = new Container();
        $app->instance('log', new NullLogger());
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

    public function test_existing_link_is_read_live_without_a_write(): void
    {
        $api = $this->api([['GET', $this->lead([12, 77])]]);

        Leads::ensureContactLinked($api, 42, 77);
    }

    public function test_missing_contact_is_linked_and_independently_verified(): void
    {
        $api = $this->api([
            ['GET', $this->lead([])],
            ['POST', [], ['metadata' => ['is_main' => true]]],
            ['GET', $this->lead([77])],
        ]);

        Leads::ensureContactLinked($api, 42, 77);
    }

    public function test_adding_contact_preserves_other_contacts_and_their_main_flag(): void
    {
        $api = $this->api([
            ['GET', $this->lead([12])],
            ['POST', [], []],
            ['GET', $this->lead([12, 77])],
        ]);

        Leads::ensureContactLinked($api, 42, 77);
    }

    public function test_each_sync_checks_remote_links_again(): void
    {
        $api = $this->api([
            ['GET', $this->lead([77])],
            ['GET', $this->lead([])],
            ['POST', [], ['metadata' => ['is_main' => true]]],
            ['GET', $this->lead([77])],
        ]);

        Leads::ensureContactLinked($api, 42, 77);
        Leads::ensureContactLinked($api, 42, 77);
    }

    public function test_lost_link_response_is_reconciled_without_repeating_the_write(): void
    {
        $api = $this->api([
            ['GET', $this->lead([])],
            ['POST', new ConnectionException('Connection reset'), ['metadata' => ['is_main' => true]]],
            ['GET', $this->lead([77])],
        ]);

        Leads::ensureContactLinked($api, 42, 77);

        Sleep::assertSequence([Sleep::for(5)->seconds()]);
    }

    public function test_readback_timeout_does_not_repeat_confirmed_link_write(): void
    {
        $api = $this->api([
            ['GET', $this->lead([])],
            ['POST', [], ['metadata' => ['is_main' => true]]],
            ['GET', new ConnectionException('Connection reset')],
            ['GET', $this->lead([77])],
        ]);

        Leads::ensureContactLinked($api, 42, 77);

        Sleep::assertSequence([Sleep::for(5)->seconds()]);
    }

    public function test_failed_read_does_not_become_permission_to_write(): void
    {
        $api = $this->api([['GET', new \RuntimeException('Forbidden', 403)]]);

        $this->expectExceptionMessage('Forbidden');
        Leads::ensureContactLinked($api, 42, 77);
    }

    public function test_link_is_not_successful_until_readback_confirms_it(): void
    {
        $api = $this->api([
            ['GET', $this->lead([])],
            ['POST', [], ['metadata' => ['is_main' => true]]],
            ['GET', $this->lead([])],
        ]);

        $this->expectExceptionMessage('contact 77 link to lead 42 was not confirmed');
        Leads::ensureContactLinked($api, 42, 77);
    }

    #[DataProvider('invalidLeadResponses')]
    public function test_invalid_remote_lead_never_triggers_a_link(array $response): void
    {
        $api = $this->api([['GET', $response]]);

        $this->expectException(\RuntimeException::class);
        Leads::ensureContactLinked($api, 42, 77);
    }

    public static function invalidLeadResponses(): array
    {
        return [
            'empty response' => [[]],
            'different lead' => [['id' => 99]],
            'deleted lead' => [['id' => 42, 'is_deleted' => true]],
            'invalid contacts' => [['id' => 42, '_embedded' => ['contacts' => false]]],
            'invalid contact ID' => [['id' => 42, '_embedded' => ['contacts' => [['id' => 0]]]]],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_ids_make_no_requests(int $leadId, int $contactId): void
    {
        $api = $this->createMock(Client::class);
        $api->expects($this->never())->method('requestV4');

        $this->expectException(\InvalidArgumentException::class);
        Leads::ensureContactLinked($api, $leadId, $contactId);
    }

    public static function invalidIds(): array
    {
        return [[0, 77], [42, 0], [-1, 77], [42, -1]];
    }

    private function lead(array $contactIds): array
    {
        return ['id' => 42, '_embedded' => ['contacts' => array_map(
            fn (int $id): array => ['id' => $id, 'is_main' => $id === $contactIds[0]],
            $contactIds,
        )]];
    }

    private function api(array $steps): Client
    {
        $api = $this->createMock(Client::class);
        $api->expects($this->exactly(count($steps)))->method('requestV4')->willReturnCallback(
            function (string $method, string $path, array $payload = [], array $query = []) use (&$steps): array {
                $step = array_shift($steps);
                $this->assertSame($step[0], $method);
                $this->assertSame('/api/v4/leads/42'.($method === 'POST' ? '/link' : ''), $path);
                $this->assertSame($method === 'GET' ? ['with' => 'contacts'] : [], $query);
                $this->assertSame($method === 'POST'
                    ? [['to_entity_id' => 77, 'to_entity_type' => 'contacts', ...($step[2] ?? [])]]
                    : [], $payload);
                if ($step[1] instanceof Throwable) {
                    throw $step[1];
                }

                return $step[1];
            },
        );

        return $api;
    }
}
