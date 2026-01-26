<?php

namespace App\Console\Commands\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class UpdateAll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-all';

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
        Artisan::call('install:bizon');
        Artisan::call('install:alfa');
        Artisan::call('install:getcourse');
        Artisan::call('install:tilda');
        Artisan::call('install:active-lead');
        Artisan::call('install:data-info');
        Artisan::call('install:doc');
        Artisan::call('install:distribution');
        Artisan::call('install:table');
        Artisan::call('install:analytic');
        Artisan::call('install:yclients');
        Artisan::call('install:call-transcription');
    }
}
