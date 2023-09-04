<?php

namespace App\Jobs\Tilda;

use App\Models\Core\Account;
use App\Models\Integrations\Tilda\Form;
use App\Models\Integrations\Tilda\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
        $this->onQueue('tilda_form');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('app:tilda-form-send', [
            'form'    => $this->form->id,
            'account' => $this->account->id,
            'setting' => $this->setting->id,
        ]);
    }
}
