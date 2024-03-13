<?php

namespace App\Services\Distribution\Strategies;

use App\Models\amoCRM\Staff;
use App\Models\Integrations\Distribution\Setting;
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
        $lastTransaction = $this->transactions->first();

        Log::info(__METHOD__.' last id => '.$lastTransaction->id.' base id => '.$this->transaction->id);

        //есть на кого распределять и есть на что ориентироваться (last)
        if ($lastTransaction && count($this->staffs) > 0) {

            foreach ($this->staffs as $key => $staffId) {

                if ($lastTransaction->staff_amocrm_id == $staffId) {

                    return end($this->staffs) == $staffId ? $this->staffs[0] : $this->staffs[++$key];
                }
            }
        }

        return $this->staffs[0];
    }

    public function sliceSchedule()
    {
        $now = Carbon::now()->timezone('Europe/Moscow');
        $isWork = false;

        //отбираем только тех, кто работает по графику сейчас
        foreach ($this->staffs as $staffId) {

            $staff = Staff::query()->where('staff_id', $staffId)->first();

            //коллекция объектов из рабочих периодов сотрудника
            $schedulers = $staff->schedule->settings ?? null;

            if ($schedulers) {

                $schedulers = json_decode($schedulers);

                Log::info(__METHOD__.' staff_id => '.$staff->id, $schedulers);

                foreach ($schedulers as $scheduler) {

                    if ($scheduler->type == 'work') {

                        $at = Carbon::parse($scheduler->at);
                        $to = Carbon::parse($scheduler->to);

                        if ($now > $at && $now < $to) {

                            Log::info(__METHOD__.' staff_id => '.$staff->id.' is work');

                            //проверка на выходной

                            foreach ($schedulers as $scheduler) {

                                if ($scheduler->type == 'free') {

                                    $freeAt = Carbon::parse($scheduler->at);
                                    $freeTo = Carbon::parse($scheduler->to);

                                    if ($now > $freeAt && $now < $freeTo) {
                                        //период подходит под отдых
                                        Log::info(__METHOD__.' staff_id => '.$staff->id.' is free');

                                        $isWork = false;

                                        break 2;
                                    }
                                }
                            }
                            //не подходит под периоды отдыха
                            $isWork = true;
                        }
                    }
                }
            }

            if (!$isWork)
                unset($this->staffs[$staff->staff_id]);
        }

        return $this;
    }
}
