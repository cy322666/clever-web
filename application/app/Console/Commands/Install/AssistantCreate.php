<?php

namespace App\Console\Commands\Install;

use App\Filament\Resources\Integrations\AssistantResource;
use App\Models\App;
use App\Models\Integrations\Assistant\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AssistantCreate extends Command
{
    protected $signature = 'install:assistant {user_id?}';
    protected $description = 'Install Assistant integration';
    private string $app = 'assistant';
    private string $resource = AssistantResource::class;

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

        $users = User::query()->get();

        foreach ($users as $user) {
            Artisan::call('install:assistant', ['user_id' => $user->id]);
        }
    }
}
