<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use Laravel\Horizon\Contracts\TagRepository;
use Throwable;

class SyncHorizonMonitoring extends Command
{
    protected $signature = 'horizon:sync-platform-monitoring {--dry-run : Only show tags without writing to Redis}';

    protected $description = 'Enable Horizon monitoring for platform tags from config';

    public function handle(TagRepository $tags): int
    {
        $configuredTags = collect(config('horizon.platform.monitor_tags', []))
            ->filter(static fn($tag): bool => is_string($tag) && trim($tag) !== '')
            ->map(static fn(string $tag): string => trim($tag))
            ->unique()
            ->values();

        if ($configuredTags->isEmpty()) {
            $this->warn('No Horizon platform tags configured.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $configuredTags->each(fn(string $tag) => $this->line($tag));

            return self::SUCCESS;
        }

        try {
            $alreadyMonitored = collect($tags->monitoring())
                ->map(static fn($tag): string => (string)$tag)
                ->all();

            $configuredTags
                ->reject(static fn(string $tag): bool => in_array($tag, $alreadyMonitored, true))
                ->each(fn(string $tag) => $tags->monitor($tag));
        } catch (Throwable $e) {
            $this->error('Horizon monitoring sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Horizon platform monitoring is synchronized.');

        return self::SUCCESS;
    }
}
