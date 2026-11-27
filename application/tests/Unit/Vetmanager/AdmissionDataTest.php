<?php

namespace Tests\Unit\Vetmanager;

use App\Services\Vetmanager\AdmissionData;
use PHPUnit\Framework\TestCase;

class AdmissionDataTest extends TestCase
{
    public function test_maps_admission_to_contact_and_lead_values(): void
    {
        $data = new AdmissionData([
            'id' => 123,
            'client_id' => 45,
            'patient_id' => 67,
            'clinic_id' => 3,
            'user_id' => 8,
            'status' => 'accepted',
            'admission_date' => '2026-09-07 14:30:00',
            'invoices_sum' => '2 500,50',
            'description' => 'Повторный прием',
            'client' => [
                'last_name' => 'Иванов',
                'first_name' => 'Иван',
                'phone_prefix' => '7',
                'cell_phone' => '8 (900) 123-45-67',
                'home_phone' => '+7 900 123-45-67',
                'email' => ' Owner@Example.test ',
            ],
            'pet' => ['alias' => 'Барсик'],
            'doctor_data' => [
                'last_name' => 'Петрова',
                'first_name' => 'Анна',
            ],
        ], 'Europe/Moscow');

        $this->assertSame('123', $data->id());
        $this->assertSame('Иванов Иван', $data->clientName());
        $this->assertSame('Барсик', $data->patientName());
        $this->assertSame('Петрова Анна', $data->doctorName());
        $this->assertSame(['+79001234567'], $data->phones());
        $this->assertSame('owner@example.test', $data->email());
        $this->assertSame(2500.5, $data->amount());
        $this->assertStringStartsWith('[VM#123] 07.09.2026 14:30 Барсик', $data->leadName());
    }

    public function test_returns_null_for_invalid_date_and_contact_values(): void
    {
        $data = new AdmissionData([
            'id' => 1,
            'client_id' => 2,
            'admission_date' => '0000-00-00 00:00:00',
            'client' => [
                'cell_phone' => '123',
                'email' => 'not-an-email',
            ],
        ], 'Europe/Moscow');

        $this->assertNull($data->admissionDate());
        $this->assertSame([], $data->phones());
        $this->assertNull($data->email());
    }

    public function test_maps_vetmanager_state_to_sqns_style_stages(): void
    {
        $this->assertSame('wait', (new AdmissionData(['status' => 'save'], 'Europe/Moscow'))->stageKey('admissionAdd'));
        $this->assertSame('confirm', (new AdmissionData(['status' => 'save'], 'Europe/Moscow'))->stageKey('admissionConfirmed'));
        $this->assertSame('came', (new AdmissionData(['status' => 'in_treatment'], 'Europe/Moscow'))->stageKey('admissionChanged'));
        $this->assertSame('cancel', (new AdmissionData(['confirmation' => 'cancelled'], 'Europe/Moscow'))->stageKey('admissionChanged'));
        $this->assertSame('delete', (new AdmissionData(['status' => 'deleted'], 'Europe/Moscow'))->stageKey('admissionDelete'));
    }
}
