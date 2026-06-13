<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\WidgetSubscriptionAccessService;
use Illuminate\Console\Command;

class ExpireWidgetSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire {--dry-run : Только показать статистику, без записи в БД}';

    protected $description = 'Блокирует просроченные ручные подписки на виджеты и синхронизирует apps';

    public function handle(WidgetSubscriptionAccessService $access): int
    {
        $stats = $access->expireOverdueSubscriptions((bool)$this->option('dry-run'));

        $this->info('Истечение подписок обработано.');
        $this->line('Проверено: ' . $stats['processed']);
        $this->line('Заблокировано: ' . $stats['expired']);
        $this->line('Ошибок: ' . $stats['errors']);

        return self::SUCCESS;
    }
}
