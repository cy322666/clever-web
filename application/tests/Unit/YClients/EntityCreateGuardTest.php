<?php

namespace Tests\Unit\YClients;

use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Record;
use App\Services\YClients\EntityCreateGuard;
use App\Services\YClients\UnconfirmedCreateException;
use Exception;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EntityCreateGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        (require database_path('migrations/2026_10_14_120000_create_yclients_create_receipts_table.php'))->up();
    }

    public function test_lost_create_response_is_never_posted_again_and_can_be_reconciled(): void
    {
        $record = $this->record();
        $guard = EntityCreateGuard::forLead($record);
        $posts = 0;
        $create = function () use (&$posts): never {
            $posts++;
            throw new Exception('Invalid API response (non JSON), code: 0');
        };
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $guard->create($create);
                $this->fail('The response is unconfirmed.');
            } catch (UnconfirmedCreateException) {
                $this->assertSame(1, $posts);
            }
        }
        $lead = (object) ['id' => 42];
        $this->assertSame($lead, $guard->recover(fn () => $this->fail('No known ID yet'), fn () => $lead));
        // Simulate the process dying before yclients_records.lead_id was saved.
        $this->assertSame($lead, EntityCreateGuard::forLead($record)->recover(
            function (int $id) use ($lead) { $this->assertSame(42, $id); return $lead; },
            fn () => $this->fail('Acknowledged IDs must not be searched again'),
        ));
        $this->assertSame(42, (int) DB::table('yclients_create_receipts')->value('remote_id'));
    }

    public function test_success_is_recorded_before_callers_can_fail(): void
    {
        $guard = EntityCreateGuard::forLead($this->record());
        $guard->create(fn () => (object) ['id' => 45]);
        $this->assertSame(45, (int) DB::table('yclients_create_receipts')->value('remote_id'));
        $this->expectException(UnconfirmedCreateException::class);
        $guard->create(fn () => $this->fail('A second POST is forbidden'));
    }

    public function test_empty_reconciliation_keeps_guard_even_after_cache_clear(): void
    {
        $guard = EntityCreateGuard::forLead($this->record());
        try { $guard->create(fn () => throw new Exception('Invalid API response (non JSON), code: 502')); }
        catch (UnconfirmedCreateException) {}
        \Illuminate\Support\Facades\Cache::store('array')->flush();
        try { $guard->recover(fn () => null, fn () => null); }
        catch (UnconfirmedCreateException) {}
        $this->assertTrue($guard->exists());
        $this->assertNull(DB::table('yclients_create_receipts')->value('remote_id'));
        $this->expectException(UnconfirmedCreateException::class);
        $guard->create(fn () => $this->fail('No candidate does not prove a POST was rejected'));
    }

    public function test_tenant_and_branch_isolation_and_duplicate_local_rows(): void
    {
        EntityCreateGuard::forLead($this->record())->create(fn () => (object) ['id' => 42]);
        $duplicate = $this->record();
        $duplicate->id = 99;
        $this->assertTrue(EntityCreateGuard::forLead($duplicate)->exists());
        $this->assertFalse(EntityCreateGuard::forLead($this->record(['account_id' => 2]))->exists());
        $this->assertFalse(EntityCreateGuard::forLead($this->record(['company_id' => 20]))->exists());
        $this->assertFalse(EntityCreateGuard::forLead($this->record(['record_id' => 1001]))->exists());
    }

    public function test_changed_contact_identity_cannot_claim_an_uncertain_create(): void
    {
        $client = new Client(['account_id' => 1, 'company_id' => 10, 'client_id' => 50, 'phone' => '+79261234567']);
        $guard = EntityCreateGuard::forContact($client);
        try { $guard->create(fn () => throw new Exception('Invalid API response (non JSON), code: 0')); }
        catch (UnconfirmedCreateException) {}
        $client->phone = '+79267654321';
        $this->expectException(UnconfirmedCreateException::class);
        EntityCreateGuard::forContact($client)->recover(fn () => $this->fail(), fn () => $this->fail());
    }

    public function test_zero_or_mismatched_remote_id_is_not_acknowledged(): void
    {
        $guard = EntityCreateGuard::forLead($this->record());
        $guard->create(fn () => (object) ['id' => 42]);
        try { $guard->recover(fn () => (object) ['id' => 99], fn () => null); }
        catch (UnconfirmedCreateException) {}
        $this->assertSame(42, (int) DB::table('yclients_create_receipts')->value('remote_id'));
    }

    public function test_explicit_rejection_allows_corrected_request_but_timeout_does_not(): void
    {
        $guard = EntityCreateGuard::forLead($this->record());
        try { $guard->create(fn () => throw new Exception('Invalid API response (non JSON), code: 403')); }
        catch (Exception) {}
        $this->assertFalse($guard->exists());
        $this->assertTrue((bool) DB::table('yclients_create_receipts')->value('rejected'));
        $guard->create(fn () => (object) ['id' => 42]);
        $this->assertTrue($guard->exists());
        $this->assertSame(42, (int) DB::table('yclients_create_receipts')->value('remote_id'));
    }

    private function record(array $extra = []): Record
    {
        return new Record($extra + ['account_id' => 1, 'company_id' => 10, 'record_id' => 1000]);
    }
}
