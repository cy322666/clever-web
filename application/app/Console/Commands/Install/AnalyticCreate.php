<?php

namespace App\Console\Commands\Install;

use App\Filament\Resources\Integrations\AnalyticResource;
use App\Models\App;
use App\Models\Integrations\Bizon\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AnalyticCreate extends Command
{
    private string $app = 'analytic';

    private string $resource = AnalyticResource::class;//TODO

    protected $signature = 'install:analytic {user_id?}';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->argument('user_id');

        if ($userId) {

            if (!App::query()
                ->where('user_id', $userId)
                ->where('name', $this->app)
                ->exists()) {

                $setting = \App\Models\Integrations\Analytic\Setting::query()->create([
                    'user_id' => $userId,
                ]);

                App::query()->create([
                    'name'          => $this->app,
                    'user_id'       => $userId,
                    'setting_id'    => $setting->id,
                    'resource_name' => $this->resource,
                ]);

                dump(__METHOD__.' > migrate success user : '.$userId);

            } elseif (!\App\Models\Integrations\Analytic\Setting::query()
                ->where('user_id', $userId)
                ->exists()) {

                \App\Models\Integrations\Analytic\Setting::query()->create([
                    'user_id' => $userId,
                ]);

                dump(__METHOD__.' > setting create success user : '.$userId);
            }
        } else {

            $users = User::query()->get();

            foreach ($users as $user) {

                Artisan::call('install:analytic', ['user_id' => $user->id]);
            }
        }
    }
}
