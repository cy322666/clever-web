<?php

namespace App\Jobs\ContactMerge;

use App\Models\Core\Account;
use App\Models\Integrations\ContactMerge\Setting;
use App\Services\amoCRM\Client;
use App\Services\ContactMerge\ContactMergeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class RunMerge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Setting $setting,
        public Account $account,
    )
    {
        $this->onQueue('contact_merge');
    }

    public function tags(): array
    {
        return ['contact-merge', 'client:'.$this->account->subdomain];
    }

    /**
     * Execute the job.
     * @throws \Exception
     */
    public function handle(): void
    {
        Artisan::call('app:run-merge', [
            'setting_id' => $this->setting->id,
            'account_id' => $this->account->id,
        ]);
    }
}
