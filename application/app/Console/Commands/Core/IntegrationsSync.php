<?php

namespace App\Console\Commands\Core;

use App\Models\App;
use App\Models\User;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Console\Command;

class IntegrationsSync extends Command
{
    protected $signature = 'app:integrations-sync {--user_id=} {--with-settings : Also create missing settings for every app}';

    protected $description = 'Sync integrations catalog rows for users from config/integrations.php';

    public function handle(IntegrationProvisioningService $service): int
    {
        $userId = $this->option('user_id');
        $withSettings = (bool)$this->option('with-settings');

        if ($userId) {
            $user = User::query()->find($userId);
            if (!$user) {
                $this->error('User not found: ' . $userId);

                return self::FAILURE;
            }

            $this->syncUser($service, $user, $withSettings);
            $this->info('Done for user #' . $user->id);

            return self::SUCCESS;
        }

        User::query()->select(['id'])->chunkById(200, function ($users) use ($service, $withSettings): void {
            foreach ($users as $user) {
                $this->syncUser($service, $user, $withSettings);
                $this->line('Synced user #' . $user->id);
            }
        });

        $this->info('Sync finished.');

        return self::SUCCESS;
    }

    private function syncUser(IntegrationProvisioningService $service, User $user, bool $withSettings): void
    {
        $service->syncCatalogForUser($user);

        if (!$withSettings) {
            return;
        }

        App::query()
            ->where('user_id', $user->id)
            ->get()
            ->each(fn(App $app) => $service->ensureSettingForApp($app));
    }
}

