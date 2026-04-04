<?php

namespace App\Services\Distribution\Strategies;

use App\Models\Integrations\Distribution\Setting;
use Illuminate\Support\Facades\Log;

class RotationStrategy extends BaseStrategy
{
    public ?string $type; //график
    public static string $strategy = Setting::STRATEGY_ROTATION; //стратегия

    // без графика
    // берем все распределения за сегодня
    // берем последнего и делаем +1

    public function getStaffId() : ?int
    {
        $staffs = count($this->activeStaff) > 0
            ? array_values($this->activeStaff)
            : array_values($this->staffs ?? []);

        if (count($staffs) === 0) {
            return null;
        }

        $staffId = $this->pickNextStaffByCursor($staffs);
        Log::debug(__METHOD__ . ' user_id ' . $this->transaction->user_id, ['staff_id' => $staffId]);

        return $staffId;
    }
}
