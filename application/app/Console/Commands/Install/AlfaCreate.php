<?php

namespace App\Console\Commands\Install;

use App\Models\App;
use App\Models\Integrations\Alfa\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AlfaCreate extends Command
{
    private string $app = 'alfacrm';

    private string $resource = 'App\Filament\Resources\Integrations\AlfaResource';

    protected $signature = 'install:alfa {user_id?}';

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
            }
        } else {

            $users = User::query()->get();

            foreach ($users as $user) {

                Artisan::call('install:alfa', ['user_id' => $user->id]);
            }
        }
    }
}
