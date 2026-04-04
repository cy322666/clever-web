<?php

namespace App\Console\Commands\Core;

use App\Mail\AppStatusDailySyncReport;
use App\Models\App;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class CheckDateExpire extends Command
{
    protected $signature = 'app:check-date-expire {--dry-run : Только показать изменения, без записи в БД и отправки писем}';

    protected $description = 'Ежедневно актуализирует статусы приложений и отправляет email-уведомления владельцам';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $dryRun = (bool)$this->option('dry-run');

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'emails' => 0,
            'errors' => 0,
        ];

        $changesByUser = [];

        App::query()
            ->with('user')
            ->orderBy('id')
            ->chunkById(200, function ($apps) use (&$stats, &$changesByUser, $today, $dryRun): void {
                foreach ($apps as $app) {
                    $stats['processed']++;

                    try {
                        $changes = $this->syncApp($app, $today, $dryRun);

                        if ($changes === null) {
                            continue;
                        }

                        $stats['updated']++;

                        $user = $app->user;
                        if (!$user?->email) {
                            continue;
                        }

                        $changesByUser[$user->id] ??= [
                            'user' => $user,
                            'items' => [],
                        ];

                        $changesByUser[$user->id]['items'][] = $changes;
                    } catch (Throwable $e) {
                        $stats['errors']++;
                        Log::error('app:check-date-expire sync failed', [
                            'app_id' => $app->id,
                            'user_id' => $app->user_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if (!$dryRun) {
            foreach ($changesByUser as $payload) {
                try {
                    Mail::mailer('failover')
                        ->to($payload['user']->email)
                        ->queue(
                            new AppStatusDailySyncReport(
                                user: $payload['user'],
                                items: $payload['items'],
                                syncDate: $today->copy(),
                            )
                        );

                    $stats['emails']++;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    Log::error('app:check-date-expire email failed', [
                        'user_id' => $payload['user']->id,
                        'email' => $payload['user']->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info('Синхронизация apps завершена.');
        $this->line('Обработано: ' . $stats['processed']);
        $this->line('Обновлено: ' . $stats['updated']);
        $this->line('Писем в очередь: ' . $stats['emails']);
        $this->line('Ошибок: ' . $stats['errors']);

        return self::SUCCESS;
    }

    private function syncApp(App $app, Carbon $today, bool $dryRun): ?array
    {
        $statusBefore = (int)$app->status;
        $expiresBefore = $app->expires_tariff_at;
        $expiresCurrent = $expiresBefore;
        $changes = [];

        if (blank($expiresCurrent) && $statusBefore === App::STATE_ACTIVE) {
            $expiresCurrent = $today->copy()->addDays(30)->toDateString();
            $app->expires_tariff_at = $expiresCurrent;
            $changes[] = 'Назначена дата окончания периода: ' . $expiresCurrent;
        }

        if (filled($expiresCurrent) && $statusBefore === App::STATE_CREATED) {
            $expiresCurrent = null;
            $app->expires_tariff_at = null;
            $changes[] = 'Очищена дата окончания для состояния "Создана"';
        }

        $isExpired = $this->isExpired($expiresCurrent, $today);
        $targetStatus = $this->resolveTargetStatus($statusBefore, $isExpired);

        if ($targetStatus !== $statusBefore) {
            $app->status = $targetStatus;
            $changes[] = sprintf(
                'Статус изменен: %s → %s',
                $this->statusLabel($statusBefore),
                $this->statusLabel($targetStatus),
            );
        }

        $setting = null;
        try {
            $setting = $app->getSettingModel();
        } catch (Throwable $e) {
            Log::warning('app:check-date-expire setting resolve failed', [
                'app_id' => $app->id,
                'resource_name' => $app->resource_name,
                'setting_id' => $app->setting_id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($setting) {
            $shouldBeActive = $targetStatus === App::STATE_ACTIVE && !$isExpired;
            $isSettingActive = (bool)$setting->active;

            if ($isSettingActive !== $shouldBeActive) {
                $setting->active = $shouldBeActive;
                $changes[] = $shouldBeActive
                    ? 'Настройка интеграции автоматически включена'
                    : 'Настройка интеграции автоматически выключена';
            }
        }

        if ($changes === []) {
            return null;
        }

        if (!$dryRun) {
            if ($app->isDirty()) {
                $app->save();
            }

            if ($setting && $setting->isDirty()) {
                $setting->save();
            }
        }

        return [
            'app_id' => $app->id,
            'app_name' => $this->resolveAppName($app),
            'status_before' => $statusBefore,
            'status_after' => (int)$app->status,
            'status_before_label' => $this->statusLabel($statusBefore),
            'status_after_label' => $this->statusLabel((int)$app->status),
            'expires_before' => $expiresBefore,
            'expires_after' => $app->expires_tariff_at,
            'changes' => $changes,
        ];
    }

    private function isExpired(null|string $expiresAt, Carbon $today): bool
    {
        if (blank($expiresAt)) {
            return false;
        }

        return Carbon::parse($expiresAt)->startOfDay()->lt($today);
    }

    private function resolveTargetStatus(int $currentStatus, bool $isExpired): int
    {
        if ($isExpired && $currentStatus !== App::STATE_CREATED) {
            return App::STATE_EXPIRES;
        }

        if (!$isExpired && $currentStatus === App::STATE_EXPIRES) {
            return App::STATE_INACTIVE;
        }

        return $currentStatus;
    }

    private function resolveAppName(App $app): string
    {
        try {
            if (is_string($app->resource_name) && class_exists($app->resource_name)) {
                $name = $app->resource_name::getRecordTitle();
                if (is_string($name) && $name !== '') {
                    return $name;
                }
            }
        } catch (Throwable) {
            // fallback below
        }

        return $app->name ?: ('App #' . $app->id);
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            App::STATE_CREATED => 'Создана',
            App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
            App::STATE_ACTIVE => App::STATE_ACTIVE_WORD,
            App::STATE_EXPIRES => App::STATE_EXPIRES_WORD,
            default => 'Неизвестно',
        };
    }
}
