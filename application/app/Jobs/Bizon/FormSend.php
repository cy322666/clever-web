<?php

namespace App\Jobs\Bizon;

use App\Models\Core\Account;
use App\Models\Integrations\Bizon\Form;
use App\Models\Integrations\Bizon\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class FormSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Form $form,
        public Account $account,
        public Setting $setting,
    )
    {
        $this->onQueue('bizon_form');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('app:bizon-form-send', [
            'form'    => $this->form->id,
            'account' => $this->account->id,
            'setting' => $this->setting->id,
        ]);
    }
}
