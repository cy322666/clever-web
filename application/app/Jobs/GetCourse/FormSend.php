<?php

namespace App\Jobs\GetCourse;

use App\Models\Core\Account;
use App\Models\Integrations\GetCourse\Form;
use App\Models\Integrations\GetCourse\Setting;
use App\Models\User;
use App\Models\Webhook;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class FormSend implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        private Form $form,
        private Account $acccount,
        private Setting $setting,
    )
    {
        $this->onQueue('getcourse_form');
    }

    public function uniqueId()
    {
        return $this->setting->id;
    }

    /**
     * Execute the job.
     *
     * @return false
     */
    public function handle(): bool
    {
        Artisan::call('app:getcourse-form-send', [
            'form'    => $this->form,
            'account' => $this->acccount,
            'setting' => $this->setting,
        ]);
    }
}
