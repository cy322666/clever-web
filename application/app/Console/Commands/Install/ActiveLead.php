<?php

namespace App\Console\Commands\Install;

use App\Filament\Resources\Integrations\ActiveLeadResource;
use App\Models\App;
use App\Models\Integrations\ActiveLead\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ActiveLead extends Command
{
    private string $app = 'active-lead';

    private string $resource = ActiveLeadResource::class;

    protected $signature = 'install:active-lead {user_id?}';

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
        $userId = $this->argument('user_id');

        if ($userId) {

            if (!App::query()
                ->where('user_id', $userId)
                ->where('name', $this->app)
                ->exists()) {

                $setting = Setting::query()->create([
                    'user_id' => $userId,
                ]);

                App::query()->create([
                    'name'          => $this->app,
                    'user_id'       => $userId,
                    'setting_id'    => $setting->id,
                    'resource_name' => $this->resource,
                ]);

                dump(__METHOD__.' > migrate success user : '.$userId);
            }
        } else {

            $users = User::query()->get();

            foreach ($users as $user) {

                Artisan::call('install:active-lead', ['user_id' => $user->id]);
            }
        }
    }
}
