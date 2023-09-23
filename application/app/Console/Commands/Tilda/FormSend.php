<?php

namespace App\Console\Commands\Tilda;

use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\Tilda\Form;
use App\Models\Integrations\Tilda\FormNote;
use App\Models\Integrations\Tilda\Setting;
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
    protected $signature = 'app:tilda-form-send {form} {account} {setting}';

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
    public function handle(): bool
    {
        $form    = Form::find($this->argument('form'));
        $account = Account::find($this->argument('account'));
        $setting = Setting::find($this->argument('setting'));
        $body    = json_decode($form->body);

        $setting = json_decode($setting->settings, true)[$form->site];

        $amoApi = (new Client($account))
            ->setDelay(0.5)
            ->initLogs(Env::get('APP_DEBUG'));

        $pipelineId = Status::query()
            ->find($setting['pipeline_id'])
            ?->pipeline_id;

        $responsibleId = Staff::query()
            ->find($setting['responsible_user_id'])
            ?->staff_id;

        $phone = Form::getValueForKey('phone', $body, $setting);
        $phone = Contacts::clearPhone($phone);

        $contact = Contacts::search([
            'Телефоны' => [$phone],
            'Почта'    => Form::getValueForKey('email', $body, $setting),
        ], $amoApi);

        if ($contact == null) {

            $contact = Contacts::create($amoApi, Form::getValueForKey('name', $body, $setting) ?? 'Неизвестно');
            $contact = Contacts::update($contact, [
                'Телефоны' => [$phone],
                'Почта'    => Form::getValueForKey('email', $body, $setting),
                'Ответственный' => $responsibleId,
            ]);

        } elseif ($setting['is_union'] == 'yes') {

            $lead = Leads::search($contact, $amoApi, $pipelineId);

            if ($lead)
                $responsibleId = $lead->responsible_user_id;
        }

        if (empty($lead)) {

            $lead = Leads::create($contact, [
                'responsible_user_id' => $responsibleId,
                'pipeline_id' => $pipelineId,
            ], 'Новая заявка с Тильды');
        }

        if ($setting['utms'] == 'rewrite') {

            $lead = Leads::setRewriteUtms($lead, $form->parseCookies());
        } else
            $lead = Leads::setUtms($lead, $form->parseCookies());

        if (isset($setting['fields'])) {

            $lead = $form->setCustomFields($lead, $setting['fields']);
        }

        Tags::add($lead, $setting['tag'] ?? null);
        Tags::add($lead, 'tilda');

        Notes::addOne($lead, FormNote::create($form));

        $form->contact_id = $contact->id;
        $form->lead_id = $lead->id;
        $form->status = 1;
        $form->save();

        return true;
    }
}
