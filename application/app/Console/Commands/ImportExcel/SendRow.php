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
use App\Services\amoCRM\Models\Notes;
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
            $entityTags = $this->normalizeTagValues($setting->tag);
            $leadTags = $this->normalizeTagValues(
                $importRecord->getValueForDefaultKey($setting->lead_tag_column)
            );
            $leadNote = $this->normalizeTextValue($importRecord->getValueForDefaultKey($setting->lead_note_column));

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

                Tags::add($contact, $entityTags);
            }

            $lead = Leads::create($contact ?? null, [
                'responsible_user_id' => $responsibleUserId,
                'pipeline_id' => $objectStatus->pipeline_id,
                'status_id' => $objectStatus->status_id,
                'sale' => $leadSale,
            ], $leadName, $amoApi);

            $lead = Leads::update($lead, [], $rowDataLeads ?: []);

            Tags::add($lead, $entityTags);
            Tags::add($lead, $leadTags);

            if ($leadNote !== null) {
                Notes::addOne($lead, $leadNote);
            }

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

                Tags::add($company, $entityTags);

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

    /**
     * One Excel cell can contain several lead tags separated by comma.
     *
     * @return array<int, string>
     */
    protected function normalizeTagValues(mixed $value): array
    {
        $value = $this->normalizeTextValue($value);

        if ($value === null) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn(string $tag): string => trim($tag), explode(',', $value)),
            static fn(string $tag): bool => $tag !== ''
        )));
    }

    protected function normalizeTextValue(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value !== '' ? $value : null;
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
            $value = $this->normalizeMappedFieldValue($field, $value);

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

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

    protected function normalizeMappedFieldValue(Field $field, mixed $value): mixed
    {
        return match ($field->type) {
            'numeric', 'monetary' => $this->normalizeNumericValue($value),
            'date', 'birthday' => $this->normalizeDateValue($value, false),
            'date_time' => $this->normalizeDateValue($value, true),
            'checkbox' => $this->normalizeCheckboxValue($value),
            'select', 'multiselect', 'radiobutton', 'category' => $this->normalizeEnumFieldValue($field, $value),
            default => $this->normalizeTextValue($value),
        };
    }

    protected function normalizeEnumFieldValue(Field $field, mixed $value): mixed
    {
        if ($field->type === 'multiselect') {
            $values = $this->normalizeTagValues($value);

            $resolvedValues = [];

            foreach ($values as $item) {
                $resolvedValue = $this->resolveEnumValue($field, $item);

                if ($resolvedValue !== null) {
                    $resolvedValues[] = $resolvedValue;
                }
            }

            return array_values(array_unique($resolvedValues));
        }

        $value = $this->normalizeTextValue($value);

        return $value !== null ? $this->resolveEnumValue($field, $value) : null;
    }

    protected function normalizeNumericValue(mixed $value): int|float|null
    {
        $value = $this->normalizeTextValue($value);

        if ($value === null) {
            return null;
        }

        $value = str_replace(["\xc2\xa0", ' '], '', $value);
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value) ?? '';

        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float)$value;

        return floor($number) === $number ? (int)$number : $number;
    }

    protected function normalizeDateValue(mixed $value, bool $withTime): ?string
    {
        $value = $this->normalizeTextValue($value);

        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = ((float)$value - 25569) * 86400;

            if ($timestamp > 0) {
                return gmdate($withTime ? 'Y-m-d H:i:s' : 'Y-m-d', (int)$timestamp);
            }
        }

        $formats = [
            'd.m.Y H:i:s',
            'd.m.Y H:i',
            'd.m.Y',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/Y',
        ];

        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);

            if ($date instanceof \DateTimeImmutable) {
                return $date->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
            }
        }

        $timestamp = strtotime($value);

        return $timestamp ? date($withTime ? 'Y-m-d H:i:s' : 'Y-m-d', $timestamp) : null;
    }

    protected function normalizeCheckboxValue(mixed $value): ?int
    {
        $value = $this->normalizeTextValue($value);

        if ($value === null) {
            return null;
        }

        $value = $this->normalizeEnumText($value);

        return in_array($value, ['1', 'yes', 'y', 'true', 'да', 'д', 'истина'], true) ? 1 : 0;
    }

    protected function resolveEnumValue(Field $field, string $value): ?string
    {
        $enums = json_decode($field->enums ?: '[]', true) ?: [];
        $normalizedValue = $this->normalizeEnumText($value);

        if ($normalizedValue === '') {
            return null;
        }

        $candidates = [];

        foreach ($enums as $enum) {
            $enumValue = trim((string)($enum['value'] ?? $enum['name'] ?? ''));
            $normalizedEnum = $this->normalizeEnumText($enumValue);

            if ($normalizedEnum === '') {
                continue;
            }

            if ($normalizedEnum === $normalizedValue) {
                return $enumValue;
            }

            if (str_contains($normalizedEnum, $normalizedValue) || str_contains($normalizedValue, $normalizedEnum)) {
                $candidates[$enumValue] = $enumValue;
            }
        }

        return count($candidates) === 1 ? reset($candidates) : null;
    }

    protected function normalizeEnumText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace(["\xc2\xa0", 'ё'], [' ', 'е'], $value);
        $value = preg_replace('/[^а-яa-z0-9]+/u', ' ', $value) ?? $value;

        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
