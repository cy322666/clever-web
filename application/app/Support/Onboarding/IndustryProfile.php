<?php

namespace App\Support\Onboarding;

class IndustryProfile
{
    /** @return array<string, array{ru: string, en: string}> */
    public static function options(): array
    {
        return [
            'beauty' => ['ru' => 'Красота', 'en' => 'Beauty'],
            'medicine' => ['ru' => 'Медицина', 'en' => 'Healthcare'],
            'veterinary' => ['ru' => 'Ветеринария', 'en' => 'Veterinary'],
            'education' => ['ru' => 'Образование', 'en' => 'Education'],
            'real_estate' => ['ru' => 'Недвижимость', 'en' => 'Real estate'],
            'services' => ['ru' => 'Услуги', 'en' => 'Services'],
            'other' => ['ru' => 'Другое', 'en' => 'Other'],
        ];
    }

    /** @return array<int, string> */
    public static function recommendedApps(?string $industry): array
    {
        return match ($industry) {
            'beauty' => ['yclients', 'sqns', 'distribution', 'workflows'],
            'medicine' => ['sqns', 'yclients', 'distribution', 'workflows'],
            'veterinary' => ['vetmanager', 'distribution', 'workflows'],
            'education' => ['tilda', 'import-excel', 'workflows'],
            'real_estate', 'services' => ['tilda', 'distribution', 'workflows'],
            default => [],
        };
    }
}
