<?php

namespace App\Jobs\Integrations;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class SendAmoCrmOwnershipAlert implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public int $uniqueFor = 300;

    public function __construct(public string $kind, public string $domain, public array $userIds, public array $accountIds)
    {
        sort($this->userIds);
        sort($this->accountIds);
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return hash('sha256', json_encode([$this->kind, $this->domain, $this->userIds, $this->accountIds]));
    }

    public function handle(): void
    {
        // Also silence legacy missing-ID notifications that were queued before the update.
        if ($this->kind === 'missing_id') {
            return;
        }

        $cache = Cache::store(config('alerts.cache_store', 'monitoring'));
        $key = 'amocrm:ownership-alert:'.hash('sha256', json_encode([$this->kind, $this->domain, $this->userIds, $this->accountIds]));
        if ($cache->has($key)) {
            return;
        }

        $token = trim((string) config('widget_lifecycle.telegram.token'));
        $chatId = trim((string) config('widget_lifecycle.telegram.chat_id'));
        if ($token === '' || $chatId === '') {
            throw new \RuntimeException('Ownership alert Telegram channel is not configured.');
        }
        $lines = ['Конфликт владельцев amoCRM', 'Домен: '.$this->domain, 'Автоматическая перепривязка заблокирована. Нужно выбрать правильного владельца.'];
        $lines[] = 'Пользователи платформы: '.implode(', ', array_slice($this->userIds, 0, 30));
        $lines[] = 'ID подключений: '.implode(', ', array_slice($this->accountIds, 0, 30));
        $lines[] = 'Время: '.now()->format('d.m.Y H:i:s');
        $body = ['chat_id' => $chatId, 'text' => implode("\n", $lines), 'disable_web_page_preview' => true];
        if (filled(config('widget_lifecycle.telegram.message_thread_id'))) {
            $body['message_thread_id'] = config('widget_lifecycle.telegram.message_thread_id');
        }
        try {
            $response = Http::asForm()->connectTimeout(3)->timeout(8)
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', $body);
        } catch (\Throwable) {
            throw new \RuntimeException('Ownership alert delivery failed.');
        }
        if (! $response->successful() || $response->json('ok') !== true) {
            throw new \RuntimeException('Ownership alert was rejected by Telegram.');
        }
        $cache->put($key, true, 86400);
    }
}
