<?php

namespace App\Console\Commands\Install;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class All extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'install:all {user_id}';

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
        $user = User::query()->find($this->argument('user_id'));

        /* создание моделей интеграции */
        Artisan::call('install:alfa', ['user_id' => $user->id]);
        Artisan::call('install:bizon', ['user_id' => $user->id]);
        Artisan::call('install:getcourse', ['user_id' => $user->id]);
        Artisan::call('install:tilda', ['user_id' => $user->id]);
        Artisan::call('install:active-lead', ['user_id' => $user->id]);
        Artisan::call('install:data-info', ['user_id' => $user->id]);
        Artisan::call('install:doc', ['user_id' => $user->id]);
        Artisan::call('install:distribution', ['user_id' => $user->id]);
        Artisan::call('install:table', ['user_id' => $user->id]);
        Artisan::call('install:yclients', ['user_id' => $user->id]);
        Artisan::call('install:contact-merge', ['user_id' => $user->id]);
    }
}
