<?php

namespace Tests\Unit\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use PHPUnit\Framework\TestCase;

class SettingMappingTest extends TestCase
{
    public function test_unconfigured_branch_uses_global_mapping(): void
    {
        $setting = $this->setting();

        $mapping = $setting->amoMappingForCompany('999');

        $this->assertSame([10, 11], $mapping['pipelines']);
        $this->assertSame('100.101', $mapping['status_id_wait']);
        $this->assertSame('100.102', $mapping['status_id_confirm']);
    }

    public function test_branch_override_replaces_pipeline_and_statuses(): void
    {
        $setting = $this->setting([
            'branch_settings' => [[
                'company_id' => '123',
                'pipeline_id' => '20',
                'status_id_cancel' => '20.201',
                'status_id_wait' => '20.202',
                'status_id_came' => '20.203',
                'status_id_confirm' => '20.204',
                'status_id_delete' => '20.205',
            ]],
        ]);

        $mapping = $setting->amoMappingForCompany(123);
        $record = new Record([
            'company_id' => 123,
            'attendance' => 2,
        ]);

        $this->assertSame([20], $mapping['pipelines']);
        $this->assertSame('20.204', $mapping['status_id_confirm']);
        $this->assertSame('204', $record->getStatusId($setting)->status_id);
        $this->assertSame('20', $record->getStatusId($setting)->pipeline_id);
    }

    /** @param array<string, mixed> $attributes */
    private function setting(array $attributes = []): Setting
    {
        return new Setting(array_merge([
            'pipelines' => [10, 11],
            'status_id_cancel' => '100.100',
            'status_id_wait' => '100.101',
            'status_id_came' => '100.103',
            'status_id_confirm' => '100.102',
            'status_id_delete' => '100.104',
        ], $attributes));
    }
}
