<?php

namespace Tests\Unit\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use App\Services\YClients\Leads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ufee\Amo\Base\Services\Service;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;
use Ufee\Amo\Oauthapi;

class LeadBudgetTest extends TestCase
{
    #[DataProvider('contactModes')]
    public function test_create_does_not_assign_record_cost_to_budget(bool $withContact): void
    {
        $lead = $this->lead();
        $lead->expects($this->once())->method('save')->willReturn(true);
        $contact = null;
        $client = null;

        if ($withContact) {
            $contact = $this->createMock(Contact::class);
            $contact->expects($this->once())->method('createLead')->willReturn($lead);
        } else {
            $leads = new class($lead) {
                public function __construct(private Lead $lead) {}
                public function create(): Lead { return $this->lead; }
            };
            $service = $this->createMock(Oauthapi::class);
            $service->expects($this->once())->method('__call')->with('leads', [])->willReturn($leads);
            $client = $this->createMock(Client::class);
            $client->service = $service;
        }

        $result = Leads::create(
            $contact,
            (object)['status_id' => 101, 'pipeline_id' => 10],
            new Record(['record_id' => 777, 'cost' => 3000]),
            55,
            $client,
        );

        $this->assertSame($lead, $result);
        $this->assertNull($lead->sale);
        $this->assertArrayNotHasKey('sale', $lead->getChangedData());
        $this->assertSame(101, $lead->status_id);
        $this->assertSame(10, $lead->pipeline_id);
        $this->assertSame(55, $lead->responsible_user_id);
    }

    public static function contactModes(): array
    {
        return ['with contact' => [true], 'without contact' => [false]];
    }

    public function test_update_preserves_existing_budget(): void
    {
        $lead = $this->lead(['id' => 42, 'sale' => 12500]);
        $lead->expects($this->once())->method('save')->willReturn(true);

        Leads::update(
            $lead,
            (object)['status_id' => 102, 'pipeline_id' => 10],
            new Record(['record_id' => 777, 'cost' => 3000]),
            55,
        );

        $this->assertSame(12500, $lead->sale);
        $this->assertArrayNotHasKey('sale', $lead->getChangedData());
        $this->assertSame(102, $lead->status_id);
        $this->assertSame(10, $lead->pipeline_id);
        $this->assertSame(55, $lead->responsible_user_id);
    }

    #[DataProvider('budgetValues')]
    public function test_budget_changes_only_for_available_explicitly_mapped_value(
        string $source,
        mixed $value,
        mixed $expected,
        bool $changed,
    ): void {
        $lead = $this->lead(['id' => 42, 'sale' => 12500]);
        $lead->expects($this->once())->method('save')->willReturn(true);
        $setting = new Setting([
            'fields_lead' => [['field_yc' => $source, 'field_amo' => 'system:price']],
        ]);

        $setting->YCSetLeadFields($lead, [$source => $value]);

        $this->assertSame($expected, $lead->sale);
        $this->assertSame($changed, $lead->hasChanged('sale'));
    }

    public static function budgetValues(): array
    {
        return [
            'order cost' => ['cost', 3000, 3000, true],
            'actual payment' => ['record_paid', 1400.25, 1400.25, true],
            'zero payment' => ['record_paid', 0.0, 0.0, true],
            'unavailable payment' => ['record_paid', null, 12500, false],
            'empty payment' => ['record_paid', '', 12500, false],
        ];
    }

    public function test_unconfigured_mapping_does_not_change_budget(): void
    {
        $lead = $this->lead(['id' => 42, 'sale' => 12500]);
        $lead->expects($this->never())->method('save');

        (new Setting())->YCSetLeadFields($lead, ['cost' => 3000, 'record_paid' => 1400.25]);

        $this->assertSame(12500, $lead->sale);
        $this->assertArrayNotHasKey('sale', $lead->getChangedData());
    }

    private function lead(array $attributes = []): Lead
    {
        $service = $this->createMock(Service::class);

        return $this->getMockBuilder(Lead::class)
            ->setConstructorArgs([$attributes, &$service])
            ->onlyMethods(['save'])
            ->getMock();
    }
}
