<?php

namespace App\Console\Commands\ImportExcel;

use App\Jobs\ImportExcel\ProcessImportRow;
use App\Models\amoCRM\Field;
use App\Models\amoCRM\Status;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Companies;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Tags;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

class SendRow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:import-excel {setting_id} {record_id}';

    /**
     * Данные текущей строки Excel (из import_records.row_data).
     *
     * @var array
     */
    protected array $row = [];

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
        $importRecord = ImportRecord::query()->find($this->argument('record_id'));
        $setting = ImportSetting::query()->find($this->argument('setting_id'));

        if (!$importRecord || !$setting) {
            return;
        }

        $this->row = $importRecord->row_data ?? [];

        $amoApi = new Client($setting->user->account);

        $contactName = $importRecord->getValueForDefaultKey($setting->contact_name);
        $companyName = $importRecord->getValueForDefaultKey($setting->company_name);

        $rowDataLeads = $this->prepareRowData($setting->fields_leads);
        $rowDataContacts = $this->prepareRowData($setting->fields_contacts);
        $rowDataCompanies = $this->prepareRowData($setting->fields_companies);

        $leadSale = $importRecord->getValueForDefaultKey($setting->default_sale);
        $leadName = $importRecord->getValueForDefaultKey($setting->lead_name);

        $objectStatus = Status::getObject($setting->default_status_id);

        $lead = Leads::create(null, [
            'responsible_user_id' => $setting->default_responsible_user_id,
            'pipeline_id' => $objectStatus->pipeline_id,
            'status_id' => $objectStatus->status_id,
            'sale' => $leadSale,
        ], $leadName, $amoApi);

        $lead = Leads::update($lead, [], $rowDataLeads ?: []);

        Tags::add($lead, $setting->tag);

        if ($rowDataContacts) {
            $contact = Contacts::search($rowDataContacts, $amoApi);

            if (!$contact) {
                $contact = Contacts::create($amoApi, $contactName);
            }

            $contact = Contacts::update(
                $contact,
                $rowDataContacts + [
                    'Имя' => $contactName,
                    'Ответственный' => $importRecord->default_responsible_user_id,
                ]
            );

            $importRecord->contact_id = $contact->id;

            $contact->attachTag($setting->tag);
            $contact->save();

            $lead->attachContact($contact);
            $lead->save();
        }

        if ($rowDataCompanies) {

            $company = Companies::search($rowDataCompanies, $amoApi);

            if (!$company)
                $company = Companies::create($amoApi, $companyName);

            $company = Companies::update(
                $company,
                $rowDataCompanies + [
                    'Имя' => $companyName,
                    'Ответственный' => $importRecord->default_responsible_user_id,
                ]
            );

            $importRecord->company_id = $company->id;
            $importRecord->save();

            $company->attachTag($setting->tag);
            $company->save();

            $lead->attachCompany($company);
            $lead->save();
        }

        $importRecord->lead_id = $lead->id;
        $importRecord->status = ImportRecord::STATUS_COMPLETED;
        $importRecord->save();
    }

    protected function prepareRowData(array $mapping): bool|array
    {
        if (count($mapping) > 0) {
            foreach ($mapping as $map) {
                $value = $this->row[$map['excel_column']] ?? null; //заголовок столбца

                $field = Field::query()
                    ->where('field_id', $map['field_id'])
                    ->first();

                if ($field) {
                    $data[$field->name] = $value;
                }
            }
        }

        return $data ?? false;
    }
}
