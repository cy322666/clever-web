<?php

namespace App\Jobs\ActiveLead;

use App\Models\Core\Account;
use App\Models\Integrations\ActiveLead\Lead;
use App\Models\Integrations\ActiveLead\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class CheckLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Lead $model,
        public Setting $setting,
        public Account $account,
    )
    {
        $this->onQueue('active_lead');
    }

    public function tags(): array
    {
        return ['active-lead', 'client:'.$this->account->subdomain];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('app:check-lead', [
            'form'    => $this->form->id,
            'account' => $this->account->id,
            'setting' => $this->setting->id,
        ]);
    }
}
