<?php

namespace App\Console\Commands\Install;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use App\Models\App;
use App\Models\Integrations\ImportExcel\ImportSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ImportExcelCreate extends Command
{
    protected $signature = 'install:import-excel {user_id?}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install amoCRM Import widget';
    private string $app = 'import-excel';
    private string $resource = ImportResource::class;

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
                $setting = ImportSetting::query()->create([
                    'user_id' => $userId,
                    'active' => false,
                ]);

                App::query()->create([
                    'name' => $this->app,
                    'user_id' => $userId,
                    'setting_id' => $setting->id,
                    'resource_name' => $this->resource,
                ]);
            } elseif (!ImportSetting::query()
                ->where('user_id', $userId)
                ->exists()) {
                ImportSetting::query()->create([
                    'user_id' => $userId,
                    'active' => false,
                ]);
            }
        } else {
            $users = User::query()->get();

            foreach ($users as $user) {
                Artisan::call('install:import-excel', ['user_id' => $user->id]);
            }
        }
    }
}
