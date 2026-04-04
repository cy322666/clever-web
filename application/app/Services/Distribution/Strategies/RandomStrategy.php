<?php

namespace App\Services\Distribution\Strategies;

use App\Models\Integrations\Distribution\Setting;

class RandomStrategy extends BaseStrategy
{
    public static string $strategy = Setting::STRATEGY_RANDOM;
    public ?string $type;

    public function getStaffId(): ?int
    {
        $staffs = count($this->activeStaff) > 0
            ? array_values($this->activeStaff)
            : array_values($this->staffs ?? []);

        if (count($staffs) === 0) {
            return null;
        }

        return (int)$staffs[array_rand($staffs)];
    }
}

