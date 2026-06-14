<?php

namespace App\Jobs\GetCourse;

use App\Jobs\Concerns\BuildsHorizonTags;
use App\Models\Core\Account;
use App\Models\Integrations\GetCourse\Form;
use App\Models\Integrations\GetCourse\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class FormSend implements ShouldQueue
{
    use BuildsHorizonTags, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Form $form,
        public Account $account,
        public Setting $setting,
    )
    {
        $this->onQueue('getcourse_form');
    }

    public function tags(): array
    {
        return $this->horizonTags([
            'widget:getcourse',
            'queue:getcourse_form',
            $this->accountHorizonTags($this->account),
            $this->modelHorizonTag('getcourse_form', $this->form),
            $this->modelHorizonTag('getcourse_setting', $this->setting),
        ]);
    }

    public function handle()
    {
        Artisan::call('app:getcourse-form-send', [
            'form'    => $this->form->id,
            'account' => $this->account->id,
            'setting' => $this->setting->id,
        ]);
    }
}
