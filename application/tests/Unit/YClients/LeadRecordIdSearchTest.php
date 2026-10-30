<?php

namespace Tests\Unit\YClients;

use App\Services\YClients\Leads;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;
use Ufee\Amo\Base\Collections\Collection;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

class LeadRecordIdSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_exact_field_match_includes_won_lost_and_other_pipeline_leads(): void
    {
        foreach ([142, 143, 101] as $status) {
            foreach ([1000, '1000', ' 1000 '] as $value) {
                $lead = $this->lead(42, 901, $value, $status, 999);
                $this->assertSame($lead, Leads::searchByRecordId($this->contact([$lead]), 1000, [901]));
            }
        }
    }

    public function test_wrong_field_partial_value_empty_and_unrelated_record_do_not_match(): void
    {
        $leads = [$this->lead(1, 902, '1000'), $this->lead(2, 901, '10001'),
            $this->lead(3, 901, 'prefix 1000'), $this->lead(4, 901, null),
            $this->lead(5, 901, false), $this->lead(6, 901, '1000, 2000')];
        $this->assertNull(Leads::searchByRecordId($this->contact($leads), 1000, [901]));
    }

    public function test_match_can_be_after_the_first_sdk_chunk(): void
    {
        $leads = [];
        for ($id = 1; $id <= 501; $id++) $leads[] = $this->lead($id, 901, 'other');
        $matched = $this->lead(502, 901, '1000');
        $leads[] = $matched;
        $this->assertSame($matched, Leads::searchByRecordId($this->contact($leads), 1000, [901]));
    }

    public function test_missing_mapping_does_not_load_contact_leads(): void
    {
        $contact = $this->createMock(Contact::class);
        $contact->expects($this->never())->method('__get');
        $this->assertNull(Leads::searchByRecordId($contact, 1000, []));
    }

    public function test_duplicate_exact_matches_stop_instead_of_selecting_arbitrarily(): void
    {
        $this->expectExceptionMessage('Multiple amoCRM leads match YClients record 1000: 42, 43');
        Leads::searchByRecordId($this->contact([$this->lead(42, 901, '1000'), $this->lead(43, 901, 1000)]), 1000, [901]);
    }

    public function test_incomplete_load_does_not_mean_record_was_not_found(): void
    {
        $this->expectExceptionMessage('Incomplete amoCRM lead list');
        Leads::searchByRecordId($this->contact([$this->lead(42, 901, '1000')], [42, 43]), 1000, [901]);
    }

    public function test_lead_outside_the_contact_is_rejected(): void
    {
        $this->expectExceptionMessage('Unexpected amoCRM lead');
        Leads::searchByRecordId($this->contact([$this->lead(42, 901, '1000')], [99]), 1000, [901]);
    }

    public function test_transport_retry_refreshes_the_contact_and_searches_again(): void
    {
        $lead = $this->lead(42, 901, '1000');
        $fresh = $this->contact([$lead]);
        $service = new class($fresh) {
            public int $reads = 0;
            public function __construct(public Contact $fresh) {}
            public function find(int $id): Contact { $this->reads++; return $this->fresh; }
        };
        $contact = $this->createMock(Contact::class);
        $contact->method('__get')->willReturnCallback(fn($key) => match ($key) {
            'id' => 77, 'leads_id' => [42], 'service' => $service,
            'leads' => throw new \Exception('Invalid API response (non JSON), code: 503'),
            default => null,
        });
        $this->assertSame($lead, Leads::searchByRecordId($contact, 1000, [901]));
        $this->assertSame(1, $service->reads);
    }

    public function test_nontransport_read_error_is_not_treated_as_no_match(): void
    {
        $contact = $this->createMock(Contact::class);
        $contact->method('__get')->willReturnCallback(fn($key) => match ($key) {
            'id' => 77, 'leads_id' => [42],
            'leads' => throw new \RuntimeException('Forbidden', 403),
            default => null,
        });
        $this->expectExceptionMessage('Forbidden');
        Leads::searchByRecordId($contact, 1000, [901]);
    }

    public function test_other_record_id_is_not_considered_a_free_lead(): void
    {
        $this->assertTrue(Leads::hasMappedRecordId($this->lead(42, 901, '2000'), [901]));
        $this->assertFalse(Leads::hasMappedRecordId($this->lead(42, 902, '2000'), [901]));
        $this->assertFalse(Leads::hasMappedRecordId($this->lead(42, 901, ' '), [901]));
        $this->assertFalse(Leads::hasMappedRecordId($this->lead(42, 901, '2000'), []));
    }

    private function contact(array $leads, ?array $ids = null): Contact
    {
        $contact = $this->createMock(Contact::class);
        $ids ??= array_map(fn($lead) => $lead->id, $leads);
        $contact->method('__get')->willReturnCallback(fn($key) => match ($key) {
            'id' => 77, 'leads_id' => $ids, 'leads' => new Collection($leads), default => null,
        });
        return $contact;
    }

    private function lead(int $id, int $fieldId, mixed $value, int $status = 142, int $pipeline = 10): Lead
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('__get')->willReturnCallback(fn($key) => match ($key) {
            'id' => $id, 'status_id' => $status, 'pipeline_id' => $pipeline,
            'custom_fields' => [$fieldId => (object)['id' => $fieldId, 'values' => [(object)['value' => $value]]]],
            default => null,
        });
        return $lead;
    }
}
