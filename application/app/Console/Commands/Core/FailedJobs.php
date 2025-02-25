<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class FailedJobs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:failed-jobs';

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
        $jobs = app('db')->table('failed_jobs')->get();

        foreach ($jobs as $job) {

            Artisan::call('queue:retry '.$job->uuid);
        }
    }
}
