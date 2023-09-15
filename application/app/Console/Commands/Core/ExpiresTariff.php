<?php

namespace App\Console\Commands\Core;

use App\Models\App;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExpiresTariff extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:expires-tariff {days?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $apps = App::query()
            ->where('expires_tariff_at', '>=', Carbon::now()->format('Y-m-d'))
            ->get();

        foreach ($apps as $app) {

            //TODO деактивировать сеттинг
            //TODO приложение статус истек
            //TODO уведомление
        }
    }
}
