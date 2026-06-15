<?php

namespace App\Jobs\Core;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Services\Core\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPlatformTechnicalAlert implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $level,
        public string $title,
        public string $message,
        public array $context = [],
        public ?string $dedupeKey = null,
        public ?int $ttlSeconds = null,
    ) {
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'platform:technical-monitor',
            'alert:' . $this->level,
            'queue:default',
        ]);
    }

    public function handle(): void
    {
        match ($this->level) {
            'critical' => AlertService::critical(
                $this->title,
                $this->message,
                $this->context,
                $this->dedupeKey,
                $this->ttlSeconds,
            ),
            'warning' => AlertService::warning(
                $this->title,
                $this->message,
                $this->context,
                $this->dedupeKey,
                $this->ttlSeconds,
            ),
            default => AlertService::info(
                $this->title,
                $this->message,
                $this->context,
                $this->dedupeKey,
                $this->ttlSeconds,
            ),
        };
    }
}
