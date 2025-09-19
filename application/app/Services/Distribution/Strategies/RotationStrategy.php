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
        $lastTransaction = $this->transactions->first();

        Log::debug(__METHOD__.' user_id '.$this->transaction->user_id, [
            'last trans' => $lastTransaction->id ?? null,
            'last trans resp' => $lastTransaction->staff_amocrm_id ?? null]);

        if ($lastTransaction && count($this->activeStaff) > 0) {

            Log::debug(__METHOD__.' user_id '.$this->transaction->user_id, ['count staff '.count($this->activeStaff)]);

            foreach ($this->activeStaff as $key => $staffId) {

                if ($lastTransaction->staff_amocrm_id == $staffId) {

                    Log::debug(__METHOD__.' user_id '.$this->transaction->user_id, [$lastTransaction->staff_amocrm_id.' == '.$staffId]);

                    $staffId = end($this->activeStaff) == $staffId ? $this->activeStaff[0] : $this->activeStaff[++$key];

                    Log::debug(__METHOD__.' user_id '.$this->transaction->user_id, ['staff_id' => $staffId]);

                    return $staffId;
                }
            }
        }

        return $this->activeStaff[0];
    }
}
