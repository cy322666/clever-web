<?php

namespace App\Console\Commands\Install;

use App\Filament\Resources\Integrations\AmoDataResource;
use App\Models\App;
use App\Models\Integrations\AmoData\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AmoDataCreate extends Command
{
    protected $signature = 'install:amo-data {user_id?}';
    protected $description = 'Install amoCRM data ingestion integration';
    private string $app = 'amo-data';
    private string $resource = AmoDataResource::class;

    public function handle()
    {
        $userId = $this->argument('user_id');

        if ($userId) {
            $setting = Setting::query()
                ->where('user_id', $userId)
                ->first();

            if (!$setting) {
                $setting = Setting::query()->create([
                    'user_id' => $userId,
                ]);
            }

            App::query()->updateOrCreate([
                'user_id' => $userId,
                'name' => $this->app,
            ], [
                'setting_id' => $setting->id,
                'resource_name' => $this->resource,
            ]);

            return;
        }

        foreach (User::query()->get() as $user) {
            Artisan::call('install:amo-data', ['user_id' => $user->id]);
        }
    }
}
