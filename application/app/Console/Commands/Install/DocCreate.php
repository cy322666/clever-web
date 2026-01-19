<?php

namespace App\Console\Commands\Install;

use App\Filament\Resources\Integrations\DocResource;
use App\Models\App;
use App\Models\Integrations\Docs\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class DocCreate extends Command
{
    private string $app = 'docs';

    private string $resource = DocResource::class;

    protected $signature = 'install:doc {user_id?}';

    protected $description = 'Command description';

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


            }
        } else {

            $users = User::query()->get();

            foreach ($users as $user) {

                Artisan::call('install:doc', ['user_id' => $user->id]);
            }
        }
    }
}
