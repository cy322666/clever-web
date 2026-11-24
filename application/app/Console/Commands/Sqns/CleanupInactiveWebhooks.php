<?php

namespace App\Console\Commands\Sqns;

use App\Jobs\Sqns\DisconnectWebhook;
use App\Models\Integrations\Sqns\Setting;
use App\Services\Sqns\WebhookLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CleanupInactiveWebhooks extends Command
{
    protected $signature = 'sqns:webhooks:cleanup {--dry-run : Только показать кандидатов, без постановки задач}';

    protected $description = 'Удаляет webhook SQNS у отключённых, просроченных или отвязанных интеграций';

    public function handle(WebhookLifecycle $lifecycle): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = ['checked' => 0, 'candidates' => 0, 'queued' => 0, 'errors' => 0];

        Setting::query()
            ->whereNotNull('token')
            ->with('user')
            ->orderBy('id')
            ->chunkById(200, function ($settings) use ($dryRun, $lifecycle, &$stats): void {
                foreach ($settings as $setting) {
                    $stats['checked']++;

                    try {
                        if (! $lifecycle->shouldDisconnect($setting)) {
                            continue;
                        }

                        $stats['candidates']++;

                        if (! $dryRun) {
                            DisconnectWebhook::dispatch((int) $setting->id, 'reconciliation');
                            $stats['queued']++;
                        }
                    } catch (Throwable $exception) {
                        $stats['errors']++;
                        Log::warning('SQNS webhook cleanup inspection failed.', [
                            'setting_id' => $setting->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        $this->info($dryRun ? 'Проверка webhook SQNS завершена.' : 'Очистка webhook SQNS поставлена в очередь.');
        $this->line('Проверено: '.$stats['checked']);
        $this->line('Кандидатов: '.$stats['candidates']);
        $this->line('Поставлено в очередь: '.$stats['queued']);
        $this->line('Ошибок: '.$stats['errors']);

        return $stats['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
