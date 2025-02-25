<?php

namespace App\Console\Commands\GetCourse;

use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\GetCourse\Form;
use App\Models\Integrations\GetCourse\FormNote;
use App\Models\Integrations\GetCourse\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use App\Services\amoCRM\Models\Tags;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

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
        $form    = Form::find($this->argument('form'));
        $account = Account::find($this->argument('account'));
        $setting = Setting::find($this->argument('setting'));

        $setting = json_decode($setting->settings, true)[$form->form];

        $amoApi = (new Client($account))
            ->setDelay(0.5)
            ->initLogs(Env::get('APP_DEBUG'));

        $pipelineId = Status::query()
            ->find($setting['pipeline_id'] ?? $setting->pipeline_id_default)
            ?->pipeline_id;

        $responsibleId = Staff::query()
            ->find($setting['responsible_user_id'] ?? $setting->response_user_id_default)
            ?->staff_id;

        $statusId = Status::query()
            ->find($setting['status_id'] ?? $setting->status_id_default)
            ?->status_id;

        $phone = Contacts::clearPhone($form->phone);

        $contact = Contacts::search([
            'Телефоны' => [$phone ?? null],
            'Почта'    => $form->email ?? null,
        ], $amoApi);

        if ($contact == null)
            $contact = Contacts::create($amoApi, $form->name);

        elseif ($setting['is_union'] == 'yes') {

            $lead = Leads::search($contact, $amoApi, $pipelineId);

            if ($lead)
                $responsibleId = $lead->responsible_user_id;
        }

        $contact = Contacts::update($contact, [
            'Имя' => $form->name,
            'Телефоны' => [$phone],
            'Почта'    => $form->email,
            'Ответственный' => $responsibleId,
        ], $account->zone);

        if (empty($lead)) {

            $lead = Leads::create($contact, [
                'responsible_user_id' => $responsibleId,
                'status_id'           => $statusId,
            ], 'Новая заявка с Геткурс');
        }

        $utms = [
            'utm_term'   => $form->utm_term,
            'utm_source' => $form->utm_source,
            'utm_medium' => $form->utm_medium,
            'utm_content'  => $form->utm_content,
            'utm_campaign' => $form->utm_campaign,
        ];

        if ($setting['utms'] == 'rewrite')

            $lead = Leads::setRewriteUtms($lead, $utms);
        else
            $lead = Leads::setUtms($lead, $utms);

        if (isset($setting['fields']) && count($setting['fields']) > 0)

            $lead = $form->setCustomFields($lead, $setting['fields']);

        $lead->save();

        Tags::add($lead, $setting['tag'] ?? null);

        //TODO
//        Tags::add($lead, [
//            $setting->tag,
//            $setting->tag_form,
//        ]);

        Notes::addOne($lead, FormNote::create($form));

        $form->contact_id = $contact->id;
        $form->lead_id = $lead->id;
        $form->status = 1;
        $form->save();
    }
}
