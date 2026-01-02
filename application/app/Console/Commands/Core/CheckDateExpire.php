<?php

namespace App\Console\Commands\Core;

use App\Models\App;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckDateExpire extends Command
{
    protected $signature = 'app:check-date-expire';

    protected $description = 'Command description';

    public function handle()
    {
        $apps = App::query()->get();

        foreach ($apps as $app) {

            if (!$app->expires_tariff_at) {

                //активные без даты истечения

                //тестовый месяц
                $app->expires_tariff_at = Carbon::now()->addDays(30)->format('Y-m-d');
                $app->save();

            } elseif ($app->expires_tariff_at && $app->status == App::STATE_CREATED) {

                //непонятные товарищи, чистим дату истечения
                //настройки = созданы и дата истечения есть

                $app->expires_tariff_at = null;
                $app->save();

            } elseif ($app->status == App::STATE_EXPIRES) {

                //просто истекшие, выключаем виджет

                $setting = $app->getSettingModel();
                $setting->active = false;
                $setting->save();

            } elseif ($app->status == App::STATE_ACTIVE) {

                //активные

                $diffDays = Carbon::parse($app->expires_tariff_at)->diffInDays(Carbon::now());

                //истекшие
                if ($diffDays > 0) {

                    $setting = $app->getSettingModel();
                    $setting->active = false;
                    $setting->save();

                    $app->status = App::STATE_EXPIRES;
                    $app->save();
                }
            }
            //TODO
            //истекшие виджеты, уведы пока не дрочим?
            //пуши в тг себе
        }
    }
}
