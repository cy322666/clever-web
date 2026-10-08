<?php

namespace Tests\Unit\Vetmanager;

use App\Models\Integrations\Vetmanager\Setting;
use PHPUnit\Framework\TestCase;

class SettingFieldMappingTest extends TestCase
{
    public function test_exposes_supported_source_fields_and_target_types(): void
    {
        $this->assertSame([
            'client_id' => 'ID клиента Vetmanager',
        ], Setting::sourceFieldOptions('contacts'));

        $this->assertSame([
            'admission_id' => 'ID приема Vetmanager',
            'admission_date' => 'Дата приема',
            'patient_name' => 'Питомец',
            'doctor_name' => 'Врач',
            'description' => 'Описание приема',
        ], Setting::sourceFieldOptions('leads'));

        $this->assertSame(['date', 'date_time'], Setting::sourceFieldTypes('leads', 'admission_date'));
        $this->assertSame([], Setting::sourceFieldTypes('leads', 'unknown'));
    }

    public function test_builds_repeater_rows_from_existing_settings(): void
    {
        $this->assertSame([
            [
                'field_vetmanager' => 'client_id',
                'field_amo' => '101',
            ],
        ], Setting::fieldMappingRows([
            'contact_external_id_field_id' => 101,
        ], 'contacts'));

        $this->assertSame([
            [
                'field_vetmanager' => 'admission_id',
                'field_amo' => '201',
            ],
            [
                'field_vetmanager' => 'patient_name',
                'field_amo' => '202',
            ],
        ], Setting::fieldMappingRows([
            'lead_external_id_field_id' => 201,
            'lead_patient_name_field_id' => 202,
        ], 'leads'));
    }

    public function test_converts_repeater_rows_back_to_existing_settings(): void
    {
        $this->assertSame([
            'lead_external_id_field_id' => 301,
            'lead_admission_date_field_id' => null,
            'lead_patient_name_field_id' => null,
            'lead_doctor_name_field_id' => 302,
            'lead_description_field_id' => null,
        ], Setting::fieldMappingAttributes([
            [
                'field_vetmanager' => 'admission_id',
                'field_amo' => '301',
            ],
            [
                'field_vetmanager' => 'doctor_name',
                'field_amo' => 302,
            ],
            [
                'field_vetmanager' => 'unknown',
                'field_amo' => 999,
            ],
        ], 'leads'));
    }

    public function test_empty_rows_clear_all_entity_mappings(): void
    {
        $this->assertSame([
            'contact_external_id_field_id' => null,
        ], Setting::fieldMappingAttributes([], 'contacts'));
    }
}
