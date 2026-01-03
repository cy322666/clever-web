<?php

namespace App\Console\Commands\Core;

use App\Models\App;
use App\Models\Integrations\Tilda\Form;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ClearMonthLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-month-log';

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
        $apps = App::query()->select('name')
            ->distinct()
            ->pluck('name');

        $apps->map(function($name) {

            $app = App::query()
                ->where('name', $name)
                ->first();

            $app->resource_name::clearTransactions();
        });
    }
}
