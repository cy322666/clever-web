<?php

namespace App\Console\Commands\GetCourse;

use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\Bizon\Setting;
use App\Models\Integrations\Bizon\Viewer;
use App\Models\Integrations\Bizon\ViewerNote;
use App\Models\Integrations\GetCourse\Form;
use App\Models\Integrations\GetCourse\FormNote;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use App\Services\amoCRM\Models\Tags;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;

class FormSend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:getcourse-form-send {form} {account} {setting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws \Exception
     */
    public function handle()
    {
        Log::channel('getcourse-form')->info(__METHOD__.' > начало отправки form id : '.$this->argument('form'));

        $form    = Form::find($this->argument('form'));
        $setting = Setting::find($this->argument('setting'));
        $account = Account::find($this->argument('account'));

        $amoApi = (new Client($account))
            ->init()
            ->initLogs(Env::get('APP_DEBUG'));

        $statusId = Status::query()
            ->find($setting->status_id_form ?? $setting->status_id_default)
            ?->status_id;

        $responsibleId = Staff::query()
            ->find($setting->response_user_id_form ?? $setting->response_user_id_default)
            ?->staff_id;

        $contact = Contacts::search([
            'Телефоны' => [$form->phone],
            'Почта'    => $form->email,
        ], $amoApi);

        if ($contact == null) {

            $contact = Contacts::create($amoApi, $form->name);
            $contact = Contacts::update($contact, [
                'Телефоны' => [$form->phone],
                'Почта'    => $form->email,
                'Ответственный' => $responsibleId,
            ]);
        } else
            $lead = Leads::search($contact, $amoApi);

        if (empty($lead)) {

            $lead = Leads::create($contact, [
                'responsible_user_id' => $responsibleId,
                'status_id'           => $statusId,
            ], 'Новая заявка с Геткурс');

            $lead = Leads::setUtms($lead, [
                'utm_source'  => $form->utm_source ?? null,
                'utm_medium'  => $form->utm_medium ?? null,
                'utm_content' => $form->utm_content ?? null,
                'utm_term'    => $form->utm_term ?? null,
                'utm_campaign'=> $form->utm_campaign ?? null,
            ]);
        }

        Notes::addOne($lead, FormNote::create($form));

        Tags::add($lead, [
            $setting->tag,
            $setting->tag_form,
        ]);

        $form->contact_id = $contact->id;
        $form->lead_id = $lead->id;
        $form->status = 1;
        $form->save();

        return true;
    }
}
