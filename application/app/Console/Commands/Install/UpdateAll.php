<?php

namespace App\Console\Commands\Install;

use App\Models\App;
use App\Models\User;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Console\Command;

class UpdateAll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-all';

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
        User::query()->select(['id'])->chunkById(200, function ($users) use ($service): void {
            foreach ($users as $user) {
                $service->syncCatalogForUser($user);

                App::query()
                    ->where('user_id', $user->id)
                    ->get()
                    ->each(fn(App $app) => $service->ensureSettingForApp($app));
            }
        });

        $this->info('Integrations update completed.');

        return self::SUCCESS;
    }
}
