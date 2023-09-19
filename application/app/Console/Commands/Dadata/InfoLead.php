<?php

namespace App\Console\Commands\Dadata;

use App\Models\amoCRM\Field;
use App\Models\Core\Account;
use App\Models\Integrations\Dadata\Lead;
use App\Models\Integrations\Dadata\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use Dadata\DadataClient;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Models\Contact;

class InfoLead extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:data-info {data} {account} {setting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle()
    {
        $data    = Lead::find($this->argument('data'));
        $account = Account::find($this->argument('account'));
        $setting = Setting::find($this->argument('setting'));

        $amoApi = (new Client($account))
            ->setDelay(0.2)
            ->initLogs(Env::get('APP_DEBUG'));

        $lead = $amoApi->service
            ->leads()
            ->find($data->lead_id);

        $contact = $lead->contact;
        $phone   = $contact->cf('Телефон')->getValue();

        $data->contact_id = $contact->id;
        $data->phone_at   = $phone;
        $data->save();

        if ($phone) {

            $dadata = new DadataClient(
                Env::get('DADATA_TOKEN'),
                Env::get('DADATA_SECRET'),
            );

            $response = $dadata->clean("phone", $phone);

            $data->source = $response['source'];
            $data->type = $response['type'];
            $data->phone = $response['phone'];
            $data->country_code = $response['country_code'];
            $data->city_code = $response['city_code'];
            $data->number = $response['number'];
            $data->extension = $response['extension'];
            $data->provider = $response['provider'];
            $data->country = $response['country'];
            $data->region = $response['region'];
            $data->city = $response['city'];
            $data->timezone = $response['timezone'];
            $data->qc_conflict = $response['qc_conflict'];
            $data->qc = $response['qc'];
            $data->save();

            \App\Models\Integrations\Dadata\Lead::setFields([
                'field_country'  => $setting->field_country ? Field::query()->find($setting->field_country) : null,
                'field_city'     => $setting->field_city ? Field::query()->find($setting->field_city) : null,
                'field_region'   => $setting->field_region ? Field::query()->find($setting->field_region) : null,
                'field_provider' => $setting->field_provider ? Field::query()->find($setting->field_provider) : null,
                'field_timezone' => $setting->field_timezone ? Field::query()->find($setting->field_timezone) : null,
            ], $lead, $contact, $data);

            $lead->save();
            $contact->save();
        }

        $data->status = 1;
        $data->save();
    }
}
