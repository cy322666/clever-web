<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use App\Services\Workflows\Testing\WorkflowLiveAcceptance;
use App\Workflows\Engine\WorkflowDebugger;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/** Scoped runner integration: every CRM request/debugger is mocked; no database is used. */
final class WorkflowFixtureIntegrationTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'acceptance_fixture_integration',
            'database.connections.acceptance_fixture_integration' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            foreach ($this->temporaryFiles as $path) {
                if (is_file($path)) unlink($path);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function set(WorkflowLiveAcceptance $runner, string $property, mixed $value): void
    {
        (new ReflectionProperty($runner, $property))->setValue($runner, $value);
    }

    private function property(WorkflowLiveAcceptance $runner, string $property): mixed
    {
        return (new ReflectionProperty($runner, $property))->getValue($runner);
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qa-fixture-integration-');
        $this->assertNotFalse($path);
        $this->temporaryFiles[] = $path;
        return $path;
    }

    private function runner(): WorkflowLiveAcceptance
    {
        $runner = new WorkflowLiveAcceptance;
        foreach ([
            'account' => (new Account)->forceFill(['id' => 134, 'subdomain' => 'widgetscenario']),
            'source' => (new Workflow)->forceFill(['id' => 15, 'user_id' => 142]),
            'marker' => 'unit-fixture-integration',
            'reportPath' => $this->temporaryFile(),
            'report' => ['cases' => [], 'cleanup' => [], 'account' => ['amo_account_id' => 33098322]],
        ] as $key => $value) {
            $this->set($runner, $key, $value);
        }
        return $runner;
    }

    private static function checkpoint(): array
    {
        return ['schema_version' => 1, 'phase' => 'ready', 'source_workflow_id' => 15,
            'domain' => 'widgetscenario', 'amo_account_id' => 33098322];
    }

    public function test_ready_checkpoint_with_pending_read_fixture_cannot_start_or_be_overwritten(): void
    {
        $runner = $this->runner();
        $path = $this->temporaryFile();
        $checkpoint = self::checkpoint() + ['read_fixtures' => ['pending' => [
            'kind' => 'company', 'method' => 'POST', 'path' => '/api/v4/companies',
        ]]];
        $original = json_encode($checkpoint, JSON_THROW_ON_ERROR);
        file_put_contents($path, $original);
        $this->set($runner, 'recurringStatePath', $path);
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->expects($this->never())->method('requestV4');
        $this->set($runner, 'client', $client);

        try {
            (new ReflectionMethod($runner, 'beginRecurringState'))->invoke($runner);
            $this->fail('An unresolved fixture mutation must require recovery even when phase says ready.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('manual recovery', $error->getMessage());
        }

        $this->assertSame($original, file_get_contents($path));
        $this->assertFalse($this->property($runner, 'recurringStateStarted'));
    }

    public function test_ready_checkpoint_with_verified_fixtures_and_no_pending_mutation_is_accepted(): void
    {
        WorkflowLiveAcceptance::assertRecurringCheckpoint(self::checkpoint() + ['read_fixtures' => [
            'pending' => [], 'created_company_ids' => [401], 'ids' => ['companies' => 401],
        ]], 15, 'widgetscenario', 33098322);
        $this->addToAssertionCount(1);
    }

    private static function fixtureLookups(): array
    {
        return [
            '/api/v4/leads/101/notes' => 1101,
            '/api/v4/contacts/201/notes' => 1201,
            '/api/v4/companies/401/notes' => 1401,
            '/api/v4/customers/501/notes' => 1501,
            '/api/v4/leads/custom_fields' => 161,
            '/api/v4/contacts/custom_fields' => 162,
            '/api/v4/companies/custom_fields' => 163,
            '/api/v4/customers/custom_fields' => 164,
            '/api/v4/customers' => 501,
            '/api/v4/catalogs' => 601,
            '/api/v4/catalogs/601/custom_fields' => 602,
            '/api/v4/catalogs/601/elements' => 603,
            '/api/v4/customers/statuses' => 701,
            '/api/v4/customers/segments' => 702,
            '/api/v4/customers/segments/custom_fields' => 703,
            '/api/v4/customers/transactions' => 704,
            '/api/v4/customers/501/transactions' => 705,
            '/api/v4/events' => 801,
        ];
    }

    private function exerciseFixtureReads(?string $mode): array
    {
        $runner = $this->runner();
        foreach ([
            'leadId' => 101, 'contactId' => 201, 'pipelineId' => 10, 'tasks' => [301],
            'fixtureLookups' => self::fixtureLookups(), 'fixtureEntityIds' => ['companies' => 401],
            'customersMode' => $mode,
            'report' => ['cases' => [], 'cleanup' => [], 'before' => ['company_ids' => [999]]],
        ] as $key => $value) {
            $this->set($runner, $key, $value);
        }

        // Any fallback inventory lookup could select an arbitrary parent or child.
        // With verified fixture lookups every prerequisite must come from the fixture map.
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->expects($this->never())->method('requestV4');
        $this->set($runner, 'client', $client);
        $executed = [];
        $debugger = $this->createMock(WorkflowDebugger::class);
        $debugger->method('executeNode')->willReturnCallback(function (array $node) use (&$executed): array {
            $config = $node['config'];
            $executed[$config['operation']] = $config;
            return ['status' => 'completed', 'results' => [['status' => 'completed', 'output' => [
                'data' => isset($config['id']) ? ['id' => $config['id']] : [],
                'items' => [], 'count' => 0, 'has_more' => false, 'dry_run' => false,
            ]]]];
        });
        $this->app->instance(WorkflowDebugger::class, $debugger);
        (new ReflectionMethod($runner, 'exerciseReads'))->invoke($runner);
        return [$executed, $this->property($runner, 'report')['cases']];
    }

    public function test_reads_bind_verified_parent_and_child_fixture_ids_not_arbitrary_inventory(): void
    {
        [$executed, $cases] = $this->exerciseFixtureReads(null);
        $this->assertCount(count(WorkflowLiveAcceptance::visibleReadOperations()), $cases);
        foreach ($cases as $case) {
            $this->assertSame('passed', $case['status'], $case['id']);
        }
        $expected = [
            'companies.one' => ['id' => 401],
            'companies.notes.entity.one' => ['entity_id' => 401, 'id' => 1401],
            'companies.notes.one' => ['id' => 1401],
            'leads.notes.entity.one' => ['entity_id' => 101, 'id' => 1101],
            'contacts.notes.entity.one' => ['entity_id' => 201, 'id' => 1201],
            'customers.one' => ['id' => 501],
            'customers.notes.entity.one' => ['entity_id' => 501, 'id' => 1501],
            'customers.notes.one' => ['id' => 1501],
            'customers.custom_fields.one' => ['id' => 164],
            'catalogs.one' => ['id' => 601],
            'catalog_fields.one' => ['catalog_id' => 601, 'id' => 602],
            'elements.one' => ['catalog_id' => 601, 'id' => 603],
            'transactions.one' => ['id' => 704],
            'customer_transactions.one' => ['entity_id' => 501, 'id' => 705],
            'segments.one' => ['id' => 702],
            'segment_fields.one' => ['id' => 703],
        ];
        foreach ($expected as $operation => $bindings) {
            $this->assertArrayHasKey($operation, $executed);
            $this->assertSame($bindings, array_intersect_key($executed[$operation], $bindings), $operation);
        }
    }

    #[DataProvider('customerModes')]
    public function test_customer_mode_skips_only_incompatible_status_reads(?string $mode, array $expectedSkipped): void
    {
        [$executed, $cases] = $this->exerciseFixtureReads($mode);
        $skipped = [];
        foreach ($cases as $case) {
            if ($case['status'] === 'skipped') {
                $skipped[] = $case['id'];
                $this->assertStringContainsString('режим аккаунта не изменяется', $case['reason']);
                $this->assertArrayNotHasKey(substr($case['id'], 5), $executed);
            } else {
                $this->assertSame('passed', $case['status'], $case['id']);
            }
        }
        sort($skipped);
        sort($expectedSkipped);
        $this->assertSame($expectedSkipped, $skipped);
        $this->assertCount(count(WorkflowLiveAcceptance::visibleReadOperations()), $cases);
        foreach (['customers.one', 'customer_transactions.one', 'segments.one', 'segment_fields.one'] as $operation) {
            $this->assertArrayHasKey($operation, $executed);
        }
    }

    public static function customerModes(): array
    {
        return [
            'segments' => ['segments', ['read:customer_statuses.list', 'read:customer_statuses.one']],
            'periodicity is not blanket-skipped' => ['periodicity', []],
        ];
    }

    #[DataProvider('cleanupInventories')]
    public function test_cleanup_preserves_exact_contact_ids_and_only_authorized_company_additions(
        array $beforeContacts, array $afterContacts, array $beforeCompanies, array $afterCompanies,
        array $createdCompanyIds, bool $expectedOk,
    ): void {
        $runner = $this->runner();
        $this->set($runner, 'createdCompanyIds', $createdCompanyIds);
        $this->set($runner, 'report', ['cases' => [], 'cleanup' => [], 'before' => [
            'contact_ids' => $beforeContacts, 'company_ids' => $beforeCompanies,
        ]]);
        $client = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->onlyMethods(['requestV4'])->getMock();
        $client->expects($this->exactly(2))->method('requestV4')->willReturnCallback(
            function (string $method, string $path, array $body, array $query) use ($afterContacts, $afterCompanies): array {
                $this->assertSame('GET', $method);
                $this->assertSame([], $body);
                $this->assertSame(['limit' => 250, 'page' => 1], $query);
                $this->assertContains($path, ['/api/v4/contacts', '/api/v4/companies']);
                $entity = basename($path);
                $ids = $entity === 'contacts' ? $afterContacts : $afterCompanies;
                return ['_embedded' => [$entity => array_map(static fn(int $id): array => ['id' => $id], $ids)]];
            },
        );
        $this->set($runner, 'client', $client);
        (new ReflectionMethod($runner, 'cleanup'))->invoke($runner);
        $cleanup = $this->property($runner, 'report')['cleanup']['contact_company_count'];
        $this->assertSame($expectedOk, $cleanup['ok']);
        if ($expectedOk) {
            $this->assertTrue($cleanup['result']['contacts_unchanged']);
            $this->assertTrue($cleanup['result']['existing_companies_preserved']);
            $this->assertSame($createdCompanyIds, $cleanup['result']['created_qa_company_ids']);
            $this->assertSame($createdCompanyIds === [], $cleanup['result']['unchanged']);
        } else {
            $this->assertStringContainsString('outside authorized QA fixture creation', $cleanup['error']);
        }
    }

    public static function cleanupInventories(): array
    {
        return [
            'authorized company union, order independent' => [[201, 202], [202, 201], [401, 402], [403, 402, 401], [403], true],
            'unchanged existing inventory' => [[201], [201], [401], [401], [], true],
            'foreign company addition' => [[201], [201], [401], [401, 999], [], false],
            'foreign company alongside authorized fixture' => [[201], [201], [401], [401, 402, 999], [402], false],
            'existing company removed' => [[201], [201], [401, 402], [401, 403], [403], false],
            'authorized company missing' => [[201], [201], [401], [401], [402], false],
            'new contact forbidden' => [[201], [201, 202], [401], [401], [], false],
            'missing contact forbidden' => [[201, 202], [201], [401], [401], [], false],
            'contact replacement with same count forbidden' => [[201], [999], [401], [401], [], false],
            'ten companies allowed' => [[201], [201], range(401, 409), range(401, 410), [410], true],
            'eleven companies forbidden even when authorized' => [[201], [201], range(401, 410), range(401, 411), [411], false],
        ];
    }
}
