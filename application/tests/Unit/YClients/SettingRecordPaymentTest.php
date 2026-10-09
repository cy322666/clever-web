<?php

namespace Tests\Unit\YClients;

use App\Models\amoCRM\Field;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\YClients;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Ufee\Amo\Models\Lead;

class SettingRecordPaymentTest extends TestCase
{
    public function test_regular_export_uses_actual_record_payments_not_price_or_client_total(): void
    {
        $yc = $this->getMockBuilder(YClients::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getClient', 'getRecord', 'getBranchTitle'])
            ->getMock();
        $yc->method('getClient')->willReturn((object)['data' => (object)['paid' => 9000]]);
        $yc->expects($this->once())->method('getRecord')->with('10', '777')->willReturn((object)[
            'data' => (object)[
                'id' => 777,
                'cost' => 3000,
                'finance_transactions' => [
                    (object)['id' => 1, 'record_id' => 777, 'amount' => 1000],
                    (object)['id' => 2, 'record_id' => 777, 'amount' => '500.50'],
                    (object)['id' => 3, 'record_id' => 777, 'amount' => '-100.25'],
                ],
            ],
        ]);
        $yc->method('getBranchTitle')->willReturn('Branch');

        $fields = Setting::YCGetFields($yc, new Record([
            'company_id' => 10, 'record_id' => 777, 'client_id' => 555, 'cost' => 3000,
        ]));

        $this->assertSame(1400.25, $fields['record_paid']);
        $this->assertSame(3000, $fields['cost']);
        $this->assertSame(9000, $fields['paid']);
        $this->assertSame(9000, $fields['ltv']);
    }

    public function test_payment_is_scoped_to_record_and_ignores_deleted_or_duplicate_transactions(): void
    {
        $amount = Setting::YCGetRecordPaymentAmount([
            'finance_transactions' => [
                ['id' => 1, 'record_id' => 777, 'amount' => '0.10'],
                ['id' => 2, 'record_id' => '777', 'amount' => '0.20'],
                ['id' => 2, 'record_id' => 777, 'amount' => '0.20'],
                ['id' => 3, 'record_id' => 778, 'amount' => 5000],
                ['id' => 4, 'record_id' => 777, 'amount' => 1000, 'deleted' => true],
                ['id' => 5, 'record_id' => 777, 'amount' => 2000, 'deleted' => '1'],
            ],
        ], '777');

        $this->assertSame(0.3, $amount);
    }

    public function test_payments_from_record_response_without_record_id_are_included(): void
    {
        $this->assertSame(250.5, Setting::YCGetRecordPaymentAmount([
            'finance_transactions' => [['amount' => 200], (object)['amount' => '50.50']],
        ], 777));
    }

    public function test_empty_financial_transactions_mean_zero_even_for_a_paid_loyalty_visit(): void
    {
        $this->assertSame(0.0, Setting::YCGetRecordPaymentAmount([
            'cost' => 3000,
            'paid_full' => 1,
            'client' => ['paid' => 9000],
            'finance_transactions' => [],
        ], 777));
    }

    public function test_missing_or_malformed_financial_data_does_not_become_zero(): void
    {
        foreach ([
            null,
            [],
            ['cost' => 3000, 'paid_full' => 1],
            ['finance_transactions' => null],
            ['finance_transactions' => 'unavailable'],
            ['finance_transactions' => [null]],
            ['finance_transactions' => [['amount' => null]]],
            ['finance_transactions' => [['amount' => 100], ['amount' => 'invalid']]],
            ['finance_transactions' => [['amount' => INF]]],
        ] as $record) {
            $this->assertNull(Setting::YCGetRecordPaymentAmount($record, 777));
        }
    }

    #[DataProvider('paymentMappings')]
    public function test_financial_details_are_requested_only_when_payment_is_mapped(
        mixed $leadMapping,
        mixed $contactMapping,
        bool $expected,
    ): void {
        config(['services.yclients.api_url' => 'https://yclients.test/api/v1']);
        Http::preventStrayRequests();
        Http::fake(['yclients.test/*' => Http::response(['success' => true, 'data' => ['id' => 777]])]);
        $setting = new Setting([
            'partner_token' => 'test-partner', 'user_token' => 'test-user',
            'fields_lead' => $leadMapping, 'fields_contact' => $contactMapping,
        ]);

        $this->assertSame($expected, $setting->hasFieldMapping('record_paid'));
        (new YClients($setting))->getRecord('10', '777');

        Http::assertSent(function (Request $request) use ($expected): bool {
            $query = [];
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/api/v1/record/10/777'
                && $query === ($expected ? ['include_finance_transactions' => '1'] : []);
        });
        Http::assertSentCount(1);
    }

    public static function paymentMappings(): array
    {
        $payment = [['field_yc' => 'record_paid', 'field_amo' => 123]];

        return [
            'no mappings' => [null, null, false],
            'invalid mappings' => ['invalid', '', false],
            'existing total' => [json_encode([['field_yc' => 'paid', 'field_amo' => 123]]), null, false],
            'incomplete payment' => [[['field_yc' => 'record_paid', 'field_amo' => null]], null, false],
            'lead json' => [json_encode($payment), null, true],
            'contact json' => [null, json_encode($payment), true],
            'lead array' => [$payment, null, true],
            'contact array' => [null, $payment, true],
            'budget' => [[['field_yc' => 'record_paid', 'field_amo' => 'system:price']], null, true],
        ];
    }

    public function test_other_api_requests_keep_their_existing_parameters(): void
    {
        config(['services.yclients.api_url' => 'https://yclients.test/api/v1']);
        Http::preventStrayRequests();
        Http::fake(['yclients.test/*' => Http::response(['success' => true, 'data' => []])]);
        $yc = new YClients(new Setting([
            'partner_token' => 'test-partner', 'user_token' => 'test-user',
            'fields_lead' => json_encode([['field_yc' => 'record_paid', 'field_amo' => 123]]),
        ]));

        $yc->getBranches();
        $yc->getClient('10', '555');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://yclients.test/api/v1/companies?my=1');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://yclients.test/api/v1/client/10/555');
        Http::assertSentCount(2);
    }

    public function test_zero_payment_is_written_to_amo_but_unavailable_payment_is_not(): void
    {
        $customField = new class {
            public array $values = [];
            public function setValue($value): void { $this->values[] = $value; }
        };
        $customFields = new class($customField) {
            public function __construct(private object $field) {}
            public function byId(int $id): object { return $this->field; }
        };
        $lead = $this->createMock(Lead::class);
        $lead->method('__get')->with('customFields')->willReturn($customFields);
        $field = new Field(['field_id' => 123, 'type' => 'numeric']);
        $method = new ReflectionMethod(Setting::class, 'setAmoFieldById');

        $method->invoke(null, $lead, $field, 0.0);
        $method->invoke(null, $lead, $field, null);

        $this->assertSame([0.0], $customField->values);
    }
}
