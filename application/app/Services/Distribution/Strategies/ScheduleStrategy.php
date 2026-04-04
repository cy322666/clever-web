<?php

namespace App\Services\Distribution\Strategies;

use App\Models\amoCRM\Staff;
use App\Models\Integrations\Distribution\Setting;
use App\Services\Distribution\ScheduleEvaluator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ScheduleStrategy extends BaseStrategy
{
    public ?string $type; //график
    public static string $strategy = Setting::STRATEGY_SCHEDULE;

    // с графиком
    // берем все распределения за сегодня
    // берем последнего и делаем +1
    public function getStaffId() : ?int
    {
        $staffs = array_values($this->staffs ?? []);
        if (count($staffs) === 0) {
            return null;
        }

        return $this->pickNextStaffByCursor($staffs);
    }

    public function sliceSchedule()
    {
        $now = Carbon::now()->timezone($this->resolveTimezone());
        $filteredStaffs = [];

        //отбираем только тех, кто работает по графику сейчас
        foreach ($this->staffs as $staffId) {
            $staff = Staff::query()->where('staff_id', $staffId)->first();
            if (!$staff) {
                continue;
            }

            $isWork = ScheduleEvaluator::isWorkingNow(
                $staff->schedule->settings ?? null,
                $now,
                $this->resolveTimezone()
            );
            if ($isWork) {
                Log::info(__METHOD__ . ' staff_id => ' . $staff->id . ' is work');
                $filteredStaffs[] = $staffId;
            }
        }

        $this->staffs = $filteredStaffs;

        return $this;
    }
}
