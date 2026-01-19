<?php

namespace App\Console\Commands\Install;

use App\Filament\Resources\Integrations\DocResource;
use App\Filament\Resources\Integrations\TableResource;
use App\Models\App;
use App\Models\Integrations\Docs\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TableCreate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    private string $app = 'tables';

    private string $resource = TableResource::class;

    protected $signature = 'install:table {user_id?}';

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

                $setting = \App\Models\Integrations\Table\Setting::query()->create([
                    'user_id' => $userId,
                ]);

                App::query()->create([
                    'name'          => $this->app,
                    'user_id'       => $userId,
                    'setting_id'    => $setting->id,
                    'resource_name' => $this->resource,
                ]);

            } elseif (!\App\Models\Integrations\Table\Setting::query()
                ->where('user_id', $userId)
                ->exists()) {

                \App\Models\Integrations\Table\Setting::query()->create([
                    'user_id' => $userId,
                ]);
            }
        } else {

            $users = User::query()->get();

            foreach ($users as $user) {

                Artisan::call('install:table', ['user_id' => $user->id]);
            }
        }
    }
}
