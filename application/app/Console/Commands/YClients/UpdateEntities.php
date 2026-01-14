<?php

namespace App\Console\Commands\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\YClients\Contacts as ServiceContact;
use App\Services\YClients\Leads as ServiceLead;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Vgrish\Yclients\Yclients;

class UpdateEntities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'yc:update-entities {record} {account} {setting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     *
     * + день рождения
     * + эл. почту
     * + категории клиента
     * + пол
     * + скидку
     * + комментарий (в YC это "Примечание" в карточке клиента)
     * + отметку о том, отправлять поздравление в ДР или нет
     * + отметку о том, исключен клиент из рассылки или нет.
     *
     * @return int
     * @throws \Exception
     */
    public function handle()
    {
        $record  = $this->argument('record');
        $setting = $this->argument('setting');
        $amoApi  = (new Client($this->argument('account')))->init();

        if ($setting->fields_contact || $setting->fields_lead) {

            $yc = Yclients::getInstance()
                ->setPartnerToken($setting->partner_token)
                ->setUserToken($setting->user_token);

            //заполненные поля с ключами
            $arrayFields = Setting::YCGetFields($yc, $record);

            if ($setting->fields_contact) {

                $contact = Contacts::get($amoApi, $record->client->contact_id);

                $setting->YCSetContactFields($contact, $arrayFields);
            }

            if ($setting->fields_lead) {

                $lead = Leads::get($amoApi, $record->lead_id);

                $setting->YCSetLeadFields($lead, $arrayFields);
            }
        }

//  $note = $contact->createNote();
//  $note->text = $clientYC->object()->getComment();
//  $note->element_type = 1;
//  $note->element_id = $contact->id;
//  $note->save();

    }
}
