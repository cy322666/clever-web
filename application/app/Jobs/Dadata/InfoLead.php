<?php

namespace App\Jobs\Dadata;

use App\Models\Core\Account;
use App\Models\Integrations\Dadata\Lead;
use App\Models\Integrations\Dadata\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class InfoLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Lead $data,
        public Setting $setting,
        public Account $account,
    )
    {
        $this->onQueue('data_info');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('app:data-info', [
            'data'    => $this->data->id,
            'account' => $this->account->id,
            'setting' => $this->setting->id,
        ]);
    }
}
