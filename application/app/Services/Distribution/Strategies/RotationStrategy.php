<?php

namespace App\Services\Distribution;

use App\Models\Integrations\Distribution\Setting;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\User;
use App\Services\amoCRM\Client;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection;

class RotationStrategy extends BaseStrategy
{
    public ?string $type; //график
    public static string $strategy = Setting::STRATEGY_ROTATION; //стратегия
    public array $staffs = [];

    // если без графика
    // берем все распределения за сегодня
    // берем последнего и делаем +1

    public function getStaffId() : ?int
    {
        $lastTransaction = $this->transaction->last();

        if ($lastTransaction && count($this->staffs) > 0) {

            foreach ($this->staffs as $key => $staffId) {

                if ($lastTransaction->staff_id == $staffId) {

                    // крайний чел в списке
                    return end($this->staffs) == $key ? $this->staffs[0] : end($this->staffs);
                }
            }
        }
    }
}
