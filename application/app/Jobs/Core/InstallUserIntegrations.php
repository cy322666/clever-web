<?php

namespace App\Jobs\Core;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\User;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InstallUserIntegrations implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $userId)
    {
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'platform:catalog',
            'queue:default',
            $this->modelHorizonTag('user', $this->userId),
        ]);
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        if (!$user) {
            Log::warning('InstallUserIntegrations: user not found', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        app(IntegrationProvisioningService::class)->syncCatalogForUser($user);
    }
}
