<?php

namespace App\Console\Commands\Install;

use App\Models\App;
use App\Models\Integrations\GetCourse\Setting;
use Illuminate\Console\Command;

class GetCourseCreate extends Command
{
    private string $app = 'getcourse';

    private string $resource = 'App\Filament\Resources\Integrations\GetCourseResource';

    protected $signature = 'install:getcourse {user_id}';

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
