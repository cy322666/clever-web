<?php

namespace App\Console\Commands\Install;

use App\Models\App;
use App\Models\Integrations\Bizon\Setting;
use Illuminate\Console\Command;

class BizonCreate extends Command
{
    private string $app = 'bizon';

    private string $resource = 'App\Filament\Resources\Integrations\BizonResource';

    protected $signature = 'install:bizon {user_id}';

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
}
