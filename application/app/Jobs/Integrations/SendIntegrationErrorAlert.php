<?php

namespace App\Jobs\Integrations;

use App\Services\Integrations\IntegrationErrorNotifier;
use App\Services\YClients\TransientFailureAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class SendIntegrationErrorAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public int $timeout = 15;

    public array $backoff = [15, 60, 180];

    public ?TransientFailureAlert $yclientsFailure = null;

    public int $notBefore = 0;

    public function __construct(public string $message, public string $fingerprint)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $notifier = app(IntegrationErrorNotifier::class);
        if (! $notifier->enabled()) {
            return;
        }
        $cache = $notifier->cache();
        $sentKey = 'integration-errors:sent:'.$this->fingerprint;
        $pendingKey = $this->pendingKey();
        if ($cache?->has($sentKey)) {
            $cache->forget($pendingKey);

            return;
        }
        // The synchronous dispatch fallback must not bypass the recovery window.
        if ($this->notBefore > now()->timestamp) {
            $seconds = $this->notBefore - now()->timestamp;
            if ($this->job !== null) {
                $this->release($seconds);
            } else {
                Bus::dispatch($this->delay($seconds));
            }

            return;
        }
        $lock = $cache?->lock('integration-errors:delivery:'.$this->fingerprint, 20);
        if ($lock && ! $lock->get()) {
            throw new RuntimeException('Integration alert delivery is already in progress.');
        }
        try {
            if ($cache?->has($sentKey)) {
                $cache->forget($pendingKey);

                return;
            }
            if ($this->yclientsFailure !== null && ! $this->yclientsFailure->stillFailed()) {
                $cache?->forget($pendingKey);

                return;
            }
            $body = ['chat_id' => config('widget_lifecycle.telegram.chat_id'),
                'text' => mb_substr($this->message, 0, 3900), 'disable_web_page_preview' => true];
            if (filled(config('widget_lifecycle.telegram.message_thread_id'))) {
                $body['message_thread_id'] = config('widget_lifecycle.telegram.message_thread_id');
            }
            try {
                $response = Http::asForm()->connectTimeout(3)->timeout(5)
                    ->post('https://api.telegram.org/bot'.config('widget_lifecycle.telegram.token').'/sendMessage', $body);
            } catch (Throwable) {
                // Do not persist an HTTP exception containing the bot token in its URL.
                throw new RuntimeException('Telegram integration alert transport failed.');
            }
            if (! $response->successful() || $response->json('ok') !== true) {
                throw new RuntimeException('Telegram rejected integration alert (HTTP '.$response->status().').');
            }
            $cache?->put($sentKey, true, $notifier->cooldown());
            $cache?->forget($pendingKey);
            Log::info('Integration error alert delivered', [
                'fingerprint' => $this->fingerprint, 'message_id' => $response->json('result.message_id'),
            ]);
        } finally {
            $lock?->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(IntegrationErrorNotifier::class)->cache()?->forget($this->pendingKey());
        Log::warning('Integration error alert delivery exhausted retries', ['fingerprint' => $this->fingerprint]);
    }

    public function pendingKey(): string
    {
        return 'integration-errors:pending:'.$this->fingerprint
            .($this->yclientsFailure !== null ? ':'.$this->yclientsFailure->key() : '');
    }
}
