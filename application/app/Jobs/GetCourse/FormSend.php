<?php

namespace App\Jobs\GetCourse;

use App\Models\GetCourse\Form;
use App\Models\GetCourse\Setting;
use App\Models\User;
use App\Models\Webhook;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use App\Services\ManagerClients\GetCourseManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FormSend implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        public Webhook $webhook,
        public Form $form,
        public Setting $setting,
        public User $user,
    )
    {
        $this->onQueue('getcourse_form');
    }

    public function tags()
    {
        return [$this->user->email, 'getcourse_form:'.$this->form->id];
    }

    /**
     * Execute the job.
     *
     * @return false
     */
    public function handle(): bool
    {
        try {
            $manager = (new GetCourseManager($this->webhook));

            $amoApi  = $manager->amoApi;
            $account = $manager->amoAccount;

            $responsibleId = $this->setting->responsible_user_id_form ?? $this->setting->responsible_user_id_default;

            $contact = Contacts::search([
                'Телефоны' => [$this->form->phone],
                'Почта'    => $this->form->email,
            ], $amoApi);

            if ($contact == null) {

                $contact = Contacts::create($amoApi, $this->form->name);
            }

            $contact = Contacts::update($contact, [
                'Телефоны' => [$this->form->phone],
                'Почта'    => $this->form->email,
            ]);

            $lead = $contact->leads->filter(function ($lead) {

                return $lead->status_id !== 142 && $lead->status_id !== 143;

            })?->first();

            if (empty($lead)) {

                $lead = Leads::create($contact, [
                    'status_id' => $this->setting->status_id_form,
                    'responsible_user_id' => $responsibleId,
                ], 'Новая заявка GetCourse');

            } else {

                $lead->status_id = $this->setting->status_id_form;
                $lead->save();
            }

            Notes::addOne($lead, $this->form->text());

            $this->form->contact_id = $contact->id;
            $this->form->lead_id = $lead->id;
            $this->form->status = 1;
            $this->form->save();

        } catch (\Throwable $exception) {

            $this->form->error = $exception->getMessage().' '.$exception->getFile().' '.$exception->getLine();
            $this->form->save();

        }
        return true;
    }
}
