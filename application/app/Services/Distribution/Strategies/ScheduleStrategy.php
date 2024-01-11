<?php

namespace App\Services\Distribution\Strategies;

use App\Models\Integrations\Distribution\Setting;

class ScheduleStrategy
{
    public ?string $type; //график
    public static string $strategy = Setting::STRATEGY_SCHEDULE; //стратегия
    public array $staffs = [];

    // без графика
    // берем все распределения за сегодня
    // берем последнего и делаем +1

    public function getStaffId() : ?int
    {
        $lastTransaction = $this->transactions->last();

        if ($lastTransaction && count($this->staffs) > 0) {

            foreach ($this->staffs as $key => $staffId) {

                if ($lastTransaction->staff_amocrm_id == $staffId) {

                    return end($this->staffs) == $staffId ? $this->staffs[0] : $this->staffs[++$key];
                }
            }
        }

        return $this->staffs[0];
    }
}
