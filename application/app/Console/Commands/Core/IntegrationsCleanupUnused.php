<?php

namespace App\Console\Commands\Core;

use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Console\Command;

class IntegrationsCleanupUnused extends Command
{
    protected $signature = 'app:integrations-clean-unused {--apply : Apply deletions (default is dry-run)}';

    protected $description = 'Cleanup untouched integration settings and detach them from created apps';

    public function handle(IntegrationProvisioningService $service): int
    {
        $apply = (bool)$this->option('apply');
        $stats = $service->cleanupUnusedSettings($apply);

        $this->line('Mode: ' . ($apply ? 'apply' : 'dry-run'));
        $this->line('Linked candidates: ' . $stats['candidates']);
        $this->line('Linked removed: ' . $stats['removed']);
        $this->line('Orphan candidates: ' . $stats['orphan_candidates']);
        $this->line('Orphan removed: ' . $stats['orphan_removed']);
        $this->line('Stale app candidates: ' . $stats['stale_app_candidates']);
        $this->line('Stale app removed: ' . $stats['stale_app_removed']);

        if (!$apply) {
            $this->warn('Dry-run only. Re-run with --apply to persist changes.');
        }

        return self::SUCCESS;
    }
}
