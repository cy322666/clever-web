<?php

namespace App\Jobs\Tilda;

use App\Jobs\Concerns\BuildsHorizonTags;
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
        $this->onQueue('tilda_form');
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:tilda',
            'queue:tilda_form',
            $this->accountHorizonTags($this->account),
            $this->modelHorizonTag('tilda_form', $this->form),
            $this->modelHorizonTag('tilda_setting', $this->setting),
        ]);
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
