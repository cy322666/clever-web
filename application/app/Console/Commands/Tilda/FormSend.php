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
        $body    = json_decode($form->body);
        $account = Account::find($this->argument('account'));
        $setting = Setting::find($this->argument('setting'));

        $setting = json_decode($setting->settings, true)[$form->site];

        $amoApi = (new Client($account))
            ->init()
            ->setDelay(0.5)
            ->initLogs(Env::get('APP_DEBUG'));

        $pipelineId = Status::query()
            ->find($setting['pipeline_id'])
            ?->pipeline_id;

        $responsibleId = Staff::query()
            ->find($setting['responsible_user_id'])
            ?->staff_id;

        $contact = Contacts::search([
            'Телефоны' => [Contacts::clearPhone($body->{$setting['phone']})],
            'Почта'    => $body->{$setting['email']} ?? null,
        ], $amoApi);

        if ($contact == null) {

            $contact = Contacts::create($amoApi, $body->{$setting['name']} ?? 'Лид с тильды');
            $contact = Contacts::update($contact, [
                'Телефоны' => !empty($setting['phone']) ? [$body?->{$setting['phone']}] : null,
                'Почта'    => !empty($setting['email']) ? $body?->{$setting['email']} : null,
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
