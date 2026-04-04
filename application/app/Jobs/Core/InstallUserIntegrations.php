<?php

namespace App\Jobs\Core;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class InstallUserIntegrations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $userId)
    {
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

        $exitCode = Artisan::call('install:all', ['user_id' => $user->id]);

        if ($exitCode !== 0) {
            Log::error('InstallUserIntegrations: install:all failed', [
                'user_id' => $user->id,
                'exit_code' => $exitCode,
                'command_output' => trim(Artisan::output()),
            ]);

            throw new \RuntimeException('install:all returned non-zero exit code');
        }
    }
}
