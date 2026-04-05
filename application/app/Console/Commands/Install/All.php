<?php

namespace App\Console\Commands\Install;

use App\Models\App;
use App\Models\User;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Console\Command;

class All extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'install:all {user_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(IntegrationProvisioningService $service)
    {
        $user = User::query()->find($this->argument('user_id'));
        if (!$user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $service->syncCatalogForUser($user);

        App::query()
            ->where('user_id', $user->id)
            ->get()
            ->each(fn(App $app) => $service->ensureSettingForApp($app));

        return self::SUCCESS;
    }
}
