<?php

namespace App\Console\Commands\Core;

use App\Services\Core\AlertService;
use App\Services\Core\DatabaseBackupTelegram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'app:backup-db {--connection=} {--send-latest : Send the latest encrypted archive without creating or cleaning backups}';

    protected $description = 'Create a database backup and clean old backups after successful creation';

    public function handle(DatabaseBackupTelegram $telegram): int
    {
        if ($this->option('send-latest')) {
            return $this->sendArchive($telegram);
        }
        $startedAt = time();
        $connection = (string) ($this->option('connection') ?: config('database.default', 'pgsql'));

        $backupExitCode = $this->call('backup:run', [
            '--db-name' => [$connection],
            '--only-db' => true,
        ]);

        if ($backupExitCode !== self::SUCCESS) {
            $this->error('Database backup failed. Cleanup skipped to keep existing backups.');
            $this->reportFailure('Не удалось создать резервную копию БД. Старые архивы сохранены.');

            return $backupExitCode;
        }

        if (config('backup.telegram.enabled', false) && $this->sendArchive($telegram, $startedAt) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->info('Database backup completed. Cleaning old backups...');

        return $this->call('backup:clean');
    }

    private function sendArchive(DatabaseBackupTelegram $telegram, int $notBefore = 0): int
    {
        try {
            $sent = $telegram->sendLatest($notBefore);
            $this->info('Telegram received '.$sent['file'].' ('.$sent['size'].' bytes), message '.$sent['message_id']);
            Log::info('Database backup delivered to Telegram', $sent);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Backup delivery failed. Cleanup skipped.');
            $this->reportFailure($e instanceof \RuntimeException ? $e->getMessage() : 'Не удалось отправить резервную копию БД. Старые архивы сохранены.');

            return self::FAILURE;
        }
    }

    private function reportFailure(string $message): void
    {
        Log::error('Database backup failed', ['reason' => $message]);
        try {
            AlertService::critical('Резервная копия БД не доставлена', $message, [], 'database-backup-delivery', 3600);
        } catch (Throwable) {
            $this->error('Backup failure notification could not be delivered. See the application log.');
        }
    }
}
