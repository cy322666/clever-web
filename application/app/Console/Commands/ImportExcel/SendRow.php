<?php

namespace App\Console\Commands\ImportExcel;

use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Companies;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Tags;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

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

        if (!$importRecord || !$setting || $importRecord->status === ImportRecord::STATUS_COMPLETED) {
            return;
        }

        $lock = Cache::lock("import-excel:record:{$importRecord->id}", 120);

        if (!$lock->get()) {
            return;
        }

        try {
            $this->row = $importRecord->row_data ?? [];

            $amoAccount = $setting->amoAccount(false, 'import-excel');

            if (!$amoAccount || !$amoAccount->active) {
                throw new \RuntimeException('amoCRM аккаунт для import-excel не подключен или не активен.');
            }

            $amoApi = new Client($amoAccount);

            $contactName = $importRecord->getValueForDefaultKey($setting->contact_name);
            $companyName = $importRecord->getValueForDefaultKey($setting->company_name);

            $rowDataLeads = $this->prepareRowData($setting->fields_leads);
            $rowDataContacts = $this->prepareRowData($setting->fields_contacts);
            $rowDataCompanies = $this->prepareRowData($setting->fields_companies);

            $leadSale = $importRecord->getValueForDefaultKey($setting->default_sale);
            $leadName = $importRecord->getValueForDefaultKey($setting->lead_name);
            $responsibleUserId = $this->resolveResponsibleUserId($setting, $importRecord);

            $objectStatus = Status::getObject($setting->default_status_id);

            if ($rowDataContacts || $contactName) {
                $contact = $rowDataContacts ? Contacts::search($rowDataContacts, $amoApi) : null;

                if (!$contact) {
                    $contact = Contacts::create($amoApi, $contactName);
                } else {
                    $importRecord->searched_contact = true;
                }

                $contact = Contacts::update(
                    $contact,
                    ($rowDataContacts ?: [])
                    + ['Имя' => $contactName]
                    + ($responsibleUserId !== null ? ['Ответственный' => $responsibleUserId] : [])
                );

                $importRecord->contact_id = $contact->id;

                if ($setting->tag) {
                    $contact->attachTag($setting->tag);
                    $contact->save();
                }
            }

            $lead = Leads::create($contact ?? null, [
                'responsible_user_id' => $responsibleUserId,
                'pipeline_id' => $objectStatus->pipeline_id,
                'status_id' => $objectStatus->status_id,
                'sale' => $leadSale,
            ], $leadName, $amoApi);

            $lead = Leads::update($lead, [], $rowDataLeads ?: []);

            Tags::add($lead, $setting->tag);

            if ($rowDataCompanies) {
                $company = Companies::search($rowDataCompanies, $amoApi);

                if (!$company) {
                    $company = Companies::create($amoApi, $companyName);
                } else
                    $importRecord->searched_company = true;

                $company = Companies::update(
                    $company,
                    $rowDataCompanies
                    + ['Имя' => $companyName]
                    + ($responsibleUserId !== null ? ['Ответственный' => $responsibleUserId] : [])
                );

                $importRecord->company_id = $company->id;
                $importRecord->save();

                $company->attachTag($setting->tag);
                $company->save();

                $lead->attachCompany($company->id);
                $lead->save();
            }

            $importRecord->lead_id = $lead->id;
            $importRecord->status = ImportRecord::STATUS_COMPLETED;
            $importRecord->save();
        } catch (\Throwable $e) {
            $importRecord->lead_id = !empty($lead) ? $lead->id : null;
            $importRecord->contact_id = !empty($contact) ? $contact->id : null;
            $importRecord->company_id = !empty($company) ? $company->id : null;
            $importRecord->error_message = $e->getMessage();
            $importRecord->status = ImportRecord::STATUS_FAILED;
            $importRecord->save();
        } finally {
            optional($lock)->release();
        }
    }

    protected function resolveResponsibleUserId(ImportSetting $setting, ImportRecord $importRecord): ?int
    {
        $defaultResponsibleId = $setting->default_responsible_user_id
            ? (int)$setting->default_responsible_user_id
            : null;

        $responsibleValue = $importRecord->getValueForDefaultKey($setting->responsible_user_column);

        if ($responsibleValue === null || trim((string)$responsibleValue) === '') {
            return $defaultResponsibleId;
        }

        $responsibleValue = trim((string)$responsibleValue);

        if (ctype_digit($responsibleValue)) {
            $staff = Staff::query()
                ->where('user_id', $setting->user_id)
                ->where('active', true)
                ->where('staff_id', (int)$responsibleValue)
                ->first();

            if ($staff) {
                return (int)$staff->staff_id;
            }
        }

        $normalizedResponsible = $this->normalizeResponsibleName($responsibleValue);

        $staff = Staff::query()
            ->where('user_id', $setting->user_id)
            ->where('active', true)
            ->get()
            ->first(function (Staff $staff) use ($normalizedResponsible): bool {
                return $this->normalizeResponsibleName($staff->name) === $normalizedResponsible
                    || $this->normalizeResponsibleName($staff->login) === $normalizedResponsible;
            });

        return $staff ? (int)$staff->staff_id : $defaultResponsibleId;
    }

    protected function normalizeResponsibleName(?string $name): string
    {
        $name = mb_strtolower(trim((string)$name));
        $name = str_replace('ё', 'е', $name);

        return preg_replace('/\s+/u', ' ', $name) ?? $name;
    }

    protected function prepareRowData(array $mapping): bool|array
    {
        $data = [];
        $multiPhoneNames = ['Телефон', 'Phone'];
        $multiEmailNames = ['Почта', 'Email', 'E-mail'];

        if (count($mapping) === 0) {
            return false;
        }

        foreach ($mapping as $map) {
            $value = $this->row[$map['excel_column']] ?? null;
            if ($value !== null && $value !== '') {
                $value = trim((string)$value);
            }
            if ($value === '') {
                continue;
            }

            $field = Field::query()
                ->where('field_id', $map['field_id'])
                ->first();

            if (!$field) {
                continue;
            }

            $name = $field->name;

            if (in_array($name, $multiPhoneNames, true)) {
                $data['Телефоны'] = $data['Телефоны'] ?? [];
                $data['Телефоны'][] = $value;
            } elseif (in_array($name, $multiEmailNames, true)) {
                $data['Emails'] = $data['Emails'] ?? [];
                $data['Emails'][] = $value;
            } else {
                $data[$name] = $value;
            }
        }

        return $data ?: false;
    }
}
