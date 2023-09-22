<?php

namespace App\Console\Commands\Bizon;

use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\Bizon\Form;
use App\Models\Integrations\Bizon\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
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
    protected $signature = 'app:bizon-form-send {form} {account} {setting}';

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

        $amoApi = (new Client($account))
            ->setDelay(0.2)
            ->initLogs(Env::get('APP_DEBUG'));

        $statusId = Status::query()
            ->find($setting->status_id_form)
            ?->status_id;

        $responsibleId = Staff::query()
            ->find($setting->response_user_id_form)
            ?->staff_id;

        $contact = Contacts::search([
            'Телефоны' => [Contacts::clearPhone($form->phone)],
            'Почта'    => $form->email,
        ], $amoApi);

        if ($contact == null) {

            $contact = Contacts::create($amoApi, $form->name);

        } else
            $lead = Leads::search($contact, $amoApi);

        $contact = Contacts::update($contact, [
            'Имя' => $form->name,
            'Телефоны' => [Contacts::clearPhone($form->phone)],
            'Почта'    => $form->email,
            'Ответственный' => $responsibleId,
        ]);

        if (empty($lead)) {

            $lead = Leads::create($contact, [
                'responsible_user_id' => $responsibleId,
                'status_id'           => $statusId,
            ], 'Новая регистрация на Бизон');

//            $lead = Leads::setUtms($lead, [
//                'utm_source'  => $form->utm_source ?? null,
//                'utm_medium'  => $form->utm_medium ?? null,
//                'utm_content' => $form->utm_content ?? null,
//                'utm_term'    => $form->utm_term ?? null,
//                'utm_campaign'=> $form->utm_campaign ?? null,
//            ]);
        }

        Tags::add($lead, $setting->tag_form);

//        Notes::addOne($lead, FormNote::create($form));

        $form->contact_id = $contact->id;
        $form->lead_id = $lead->id;
        $form->status = 1;
        $form->save();

        return true;
    }
}
