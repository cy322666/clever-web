<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'app:backup-db {--connection=}';

    protected $description = 'Create a database backup and clean old backups after successful creation';

    public function handle(): int
    {
        $connection = (string)($this->option('connection') ?: config('database.default', 'pgsql'));

        $backupExitCode = $this->call('backup:run', [
            '--db-name' => [$connection],
            '--only-db' => true,
        ]);

        if ($backupExitCode !== self::SUCCESS) {
            $this->error('Database backup failed. Cleanup skipped to keep existing backups.');

            return $backupExitCode;
        }

        $this->info('Database backup completed. Cleaning old backups...');

        return $this->call('backup:clean');
    }
}
