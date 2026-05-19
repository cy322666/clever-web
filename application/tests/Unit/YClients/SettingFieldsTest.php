<?php

namespace Tests\Unit\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\YClients as YClientsService;
use PHPUnit\Framework\TestCase;

class SettingFieldsTest extends TestCase
{
    public function test_yc_fields_select_shows_system_keys_in_labels(): void
    {
        $fields = Setting::YCfieldsSelect();

        $this->assertSame('ID записи (record_id)', $fields['record_id']);
        $this->assertSame('ID филиала (company_id)', $fields['company_id']);
        $this->assertSame('Пол (sex) - список М/Ж/строка', $fields['sex']);
    }

    public function test_yc_get_fields_includes_record_and_company_ids(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getClient', 'getBranchTitle'])
            ->getMock();

        $yc->method('getClient')->willReturn(
            (object)[
                'data' => (object)[
                    'sex' => 'Женский',
                    'birth_date' => '1990-01-01',
                    'visits' => 12,
                    'paid' => 3456,
                ],
            ]
        );

        $yc->method('getBranchTitle')->willReturn('Филиал 10');

        $record = new Record([
            'company_id' => 10,
            'client_id' => 555,
            'record_id' => 777,
            'staff_name' => 'Мастер',
        ]);

        $fields = Setting::YCGetFields($yc, $record);

        $this->assertSame(10, $fields['company_id']);
        $this->assertSame(777, $fields['record_id']);
        $this->assertSame('Филиал 10', $fields['branch']);
        $this->assertSame('Ж', $fields['sex']);
        $this->assertSame('1990-01-01', $fields['birth_date']);
        $this->assertSame(12, $fields['visits']);
        $this->assertSame('Мастер', $fields['staff']);
        $this->assertSame(3456, $fields['ltv']);
        $this->assertSame(555, $fields['client_id']);
    }
}
