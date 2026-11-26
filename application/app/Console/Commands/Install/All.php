<?php

namespace App\Console\Commands\Install;

use App\Models\User;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Console\Command;

class All extends Command
{
    /**
     * тут походу ваще все в архив (вся папка) старый формат инсталяции юзера на платформе
     *
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

        foreach ($service->definitions($user->crm_provider) as $definition) {
            $app = $service->provisionAppForUser($user, $definition['name']);
            if ($app) {
                $service->ensureSettingForApp($app);
            }
        }

        return self::SUCCESS;
    }
}
