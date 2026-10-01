<?php

namespace App\Jobs\Integrations;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompleteAmoCrmWidgetInstallation implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public string $encryptedAuthorizationCode,
        public string $referer,
        public string $widget = 'workflows',
        public ?string $completionToken = null,
        public ?int $platformUserId = null,
    ) {
        $this->onQueue('default');
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'platform:installation',
            'integration:amoCRM',
            'widget:'.$this->widget,
            'queue:default',
        ]);
    }

    public function handle(AmoCrmWidgetInstallationService $installation): void
    {
        $arguments = [
            Crypt::decryptString($this->encryptedAuthorizationCode),
            $this->referer,
            $this->widget,
        ];
        if ($this->platformUserId ?? null) {
            $arguments[] = $this->platformUserId;
        }
        $result = $installation->install(...$arguments);

        if ($this->widget === 'finder' && ($this->completionToken ?? null)) {
            app(\App\Services\Finder\InstallationStatus::class)->put($this->completionToken, [
                'status' => 'completed',
                'user_id' => (int) $result['user']->id,
                'domain' => $result['account']->subdomain.'.amocrm.'.($result['account']->zone ?: 'ru'),
            ]);
        }

        app(AmoCrmWidgetLifecycleTelegramNotifier::class)->notify('install', $this->widget, [], [
            'account_id' => $result['account']->amo_account_id,
            'referer' => $result['account']->subdomain.'.amocrm.'.($result['account']->zone ?: 'ru'),
            'client_id' => $result['account']->client_id,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        if ($this->widget === 'finder' && ($this->completionToken ?? null)) {
            app(\App\Services\Finder\InstallationStatus::class)->put($this->completionToken, ['status' => 'failed']);
        }

        Log::critical('amocrm.widget.install failed', [
            'widget' => $this->widget,
            'referer' => $this->referer,
            'error' => $exception->getMessage(),
        ]);

        $prefix = 'services.amocrm.widgets.'.$this->widget.'.';
        $fallbackToPlatform = $this->widget !== 'import-excel' && $this->widget !== 'yclients'
            && (bool) config($prefix.'fallback_to_platform_credentials', true);

        app(AmoCrmWidgetLifecycleTelegramNotifier::class)->notify('install_failed', $this->widget, [], [
            'referer' => $this->referer,
            'client_id' => config($prefix.'client_id')
                ?: ($fallbackToPlatform ? config('services.amocrm.client_id') : null),
            'exception' => $exception,
        ]);
    }
}
