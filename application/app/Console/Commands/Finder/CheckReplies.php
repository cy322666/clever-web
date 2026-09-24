<?php

namespace App\Console\Commands\Finder;

use App\Models\Integrations\Finder\Action;
use App\Models\Integrations\Finder\Conversation;
use App\Services\Finder\ActionExecutor;
use App\Services\Finder\Monitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckReplies extends Command
{
    protected $signature = 'finder:check {--limit=100 : Максимум диалогов и действий за запуск}';

    protected $description = 'Проверить время ответа в диалогах Finder и выполнить ожидающие действия';

    public function handle(Monitor $monitor, ActionExecutor $executor): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $errors = 0;
        $ids = Conversation::query()->where('next_check_at', '<=', now())->orderBy('next_check_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $monitor->check($id);
            } catch (Throwable $exception) {
                $errors++;
                Log::warning('Finder check failed', ['conversation_id' => $id, 'exception_class' => $exception::class]);
            }
        }
        // Interrupted in-flight writes require reconciliation, not an automatic retry.
        Action::query()->where('status', 'processing')->where('updated_at', '<', now()->subHour())
            ->update(['status' => 'failed', 'error' => 'Выполнение прервано. Проверьте результат в amoCRM и истории сценариев.']);
        foreach (Action::query()->where('status', 'pending')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $executor->deliver($id);
        }
        $this->info('Проверено диалогов: '.$ids->count().'. Ошибок проверки: '.$errors.'.');

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
