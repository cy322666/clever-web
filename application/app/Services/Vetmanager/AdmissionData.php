<?php

namespace App\Services\Vetmanager;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Throwable;

class AdmissionData
{
    /**
     * @param  array<string, mixed>  $admission
     */
    public function __construct(
        public readonly array $admission,
        public readonly string $timezone,
    ) {}

    public function id(): string
    {
        return trim((string) ($this->admission['id'] ?? ''));
    }

    public function clientId(): string
    {
        return trim((string) ($this->admission['client_id'] ?? data_get($this->admission, 'client.id', '')));
    }

    public function patientId(): string
    {
        return trim((string) ($this->admission['patient_id'] ?? data_get($this->admission, 'pet.id', '')));
    }

    public function clinicId(): string
    {
        return trim((string) ($this->admission['clinic_id'] ?? ''));
    }

    public function doctorId(): string
    {
        return trim((string) ($this->admission['user_id'] ?? data_get($this->admission, 'doctor_data.id', '')));
    }

    public function status(): string
    {
        return trim((string) ($this->admission['status'] ?? ''));
    }

    public function description(): string
    {
        return trim((string) ($this->admission['description'] ?? ''));
    }

    public function amount(): float
    {
        $value = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) ($this->admission['invoices_sum'] ?? '0'));
        $value = str_replace(',', '.', (string) $value);

        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    public function admissionDate(): ?CarbonImmutable
    {
        $value = trim((string) ($this->admission['admission_date'] ?? ''));

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            new DateTimeZone($this->timezone);

            return CarbonImmutable::parse($value, $this->timezone);
        } catch (Throwable) {
            return null;
        }
    }

    public function clientName(): string
    {
        $name = $this->personName((array) data_get($this->admission, 'client', []));

        return $name !== '' ? $name : 'Клиент Vetmanager #'.($this->clientId() ?: 'unknown');
    }

    public function patientName(): string
    {
        $name = trim((string) (data_get($this->admission, 'pet.alias') ?? data_get($this->admission, 'patient.alias') ?? ''));

        return $name !== '' ? $name : 'Питомец #'.($this->patientId() ?: 'unknown');
    }

    public function doctorName(): string
    {
        $name = $this->personName((array) data_get($this->admission, 'doctor_data', []));

        return $name !== '' ? $name : trim((string) data_get($this->admission, 'doctor_data.nickname', ''));
    }

    /**
     * @return array<int, string>
     */
    public function phones(): array
    {
        $client = (array) data_get($this->admission, 'client', []);
        $prefix = preg_replace('/\D+/', '', (string) ($client['phone_prefix'] ?? '')) ?: '';
        $result = [];

        foreach (['cell_phone', 'home_phone', 'work_phone'] as $key) {
            $phone = self::formatPhone((string) ($client[$key] ?? ''), $prefix);
            $compare = self::comparablePhone($phone);

            if ($phone && $compare && ! array_key_exists($compare, $result)) {
                $result[$compare] = $phone;
            }
        }

        return array_values($result);
    }

    public function email(): ?string
    {
        $email = mb_strtolower(trim((string) data_get($this->admission, 'client.email', '')));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function leadName(): string
    {
        $date = $this->admissionDate()?->format('d.m.Y H:i');
        $name = collect([
            '[VM#'.$this->id().']',
            $date,
            $this->patientName(),
            '-',
            $this->clientName(),
        ])->filter(fn (?string $part): bool => filled($part))->implode(' ');

        return mb_strlen($name) > 250 ? mb_substr($name, 0, 250) : $name;
    }

    public static function comparablePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (! is_string($digits) || strlen($digits) < 10) {
            return null;
        }

        return substr($digits, -10);
    }

    private static function formatPhone(string $phone, string $prefix): ?string
    {
        $phone = trim($phone);
        $digits = preg_replace('/\D+/', '', $phone);

        if (! is_string($digits) || strlen($digits) < 7) {
            return null;
        }

        if (str_starts_with($phone, '+')) {
            return '+'.$digits;
        }

        if ($prefix === '7' && strlen($digits) === 11 && str_starts_with($digits, '8')) {
            return '+7'.substr($digits, 1);
        }

        if ($prefix !== '' && strlen($digits) <= 10 && ! str_starts_with($digits, $prefix)) {
            $digits = $prefix.$digits;
        }

        return '+'.$digits;
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function personName(array $person): string
    {
        return collect(['last_name', 'first_name', 'middle_name'])
            ->map(fn (string $key): string => trim((string) ($person[$key] ?? '')))
            ->filter()
            ->implode(' ');
    }
}
