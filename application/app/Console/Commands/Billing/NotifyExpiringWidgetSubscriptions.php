<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\WidgetSubscriptionAccessService;
use Illuminate\Console\Command;

class NotifyExpiringWidgetSubscriptions extends Command
{
    protected $signature = 'subscriptions:notify-expiring
        {--days=7 : За сколько дней до окончания уведомлять}
        {--dry-run : Только показать статистику, без уведомлений}';

    protected $description = 'Уведомляет поддержку о подписках на виджеты, которые скоро закончатся';

    public function handle(WidgetSubscriptionAccessService $access): int
    {
        $stats = $access->notifyExpiringSubscriptions(
            days: (int)$this->option('days'),
            dryRun: (bool)$this->option('dry-run'),
        );

        $this->info('Проверка подписок завершена.');
        $this->line('Найдено: ' . $stats['processed']);
        $this->line('Уведомлено: ' . $stats['notified']);
        $this->line('Ошибок: ' . $stats['errors']);

        return self::SUCCESS;
    }
}
