<?php

namespace App\Console\Commands\Install;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Models\App;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class YClientsCreate extends Command
{
    private string $app = 'yclients';

    private string $resource = YclientsResource::class;

    protected $signature = 'install:yclients {user_id?}';

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

                $setting = \App\Models\Integrations\YClients\Setting::query()->create([
                    'user_id' => $userId,
                    'account_id' => User::query()->find($userId)->account->id,
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

                Artisan::call('install:yclients', ['user_id' => $user->id]);
            }
        }
    }
}
