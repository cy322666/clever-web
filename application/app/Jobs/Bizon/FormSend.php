<?php

namespace App\Jobs\Bizon;

use App\Jobs\Concerns\BuildsHorizonTags;
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
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:bizon',
            'queue:bizon_form',
            $this->accountHorizonTags($this->account),
            $this->modelHorizonTag('bizon_form', $this->form),
            $this->modelHorizonTag('bizon_setting', $this->setting),
        ]);
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
