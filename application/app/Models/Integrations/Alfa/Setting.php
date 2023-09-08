<?php

namespace App\Models\Integrations\Alfa;

use App\Filament\Resources\Integrations\AlfaResource;
use App\Helpers\Traits\SettingRelation;
use App\Services\AlfaCRM\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;
use App\Services\AlfaCRM\Client as alfaApi;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'alfacrm_settings';

    public static string $resource = AlfaResource::class;

    public const CREATED = 0;
    public const RECORD = 1;
    public const CAME = 2;
    public const OMISSION = 3;

    protected $fillable = [
        'status_came_1',
        'status_came_2',
        'status_came_3',
        'status_record_1',
        'status_record_2',
        'status_record_3',
        'status_omission_1',
        'status_omission_2',
        'status_omission_3',

        'stage_record_1',
        'stage_came_1',
        'stage_omission_1',

        'active',
        'work_lead',

        'name',
        'source',
        'responsible',
        'legal_name',
        'dob',
        'note',
        'phone',
        'web',

        'branch_id',

        'domain',
        'email',
        'api_key',

        'user_id',
    ];

    public function checkStatus(string $action, int $statusId): bool
    {
        $action = 'status_'.$action;

        return match ($statusId) {

            $this->{$action.'_1'},
            $this->{$action.'_3'},
            $this->{$action.'_2'} => true,

            default => false,
        };
    }

    public static function getFieldBranch(Lead $lead, ?Contact $contact, Setting $setting): string
    {
        if ($setting->branch_id) {

            //TODO может и не найти тут эксепшен и уведомление
            $fieldBranch = \App\Models\amoCRM\Field::find($setting->branch_id);
        }

        if (!empty($fieldBranch)) {

            if ($fieldBranch->field_id) {

                $entity = $fieldBranch->entity == 1 ? $contact : $lead;

                $branchName = $entity->cf($fieldBranch->name)->getValue();
            }
        }

        return $branchName ?? false;
    }

    /*
     * // TODO не использую
     *
        $fields - json в поле
        $code - поле из альфы
        $fieldName - название поля амо в бд (в сущности)
        $fieldValues - массив со значениями для клиента в АльфаСРМ
    */
    public function getFieldValues(Lead $lead, ?Contact $contact, Setting $setting): array
    {
        $user = $setting->user;

        foreach (json_decode($this->fields) as $code => $fieldName) {

            if ($fieldName !== null) {

                $amoField = Field::query()
                    ->where('user_id', $user->id)
                    ->where('name', $fieldName)
                    ->first();

                $entity = $amoField->entity == 1 ? $contact : $lead;

                if ($amoField->field_id) {

                    $fieldValue = $entity->cf($amoField->name)->getValue();
                } else
                    $fieldValue = $entity->{$amoField->code};

                //исключительные поля
                if ($code == 'lead_source_id' && $fieldValue) {

                    $fieldValue = LeadSource::query()
                        ->where('user_id', $user->id)
                        ->where('name', $fieldValue)
                        ->first()
                            ?->source_id;
                }

                $fieldValues[$code] = $fieldValue;
            }
        }

        if (empty($fieldValues['branch_id'])) {

            $fieldValues['branch_id'] = $user->alfacrm_branches()->first()->branch_id;
        }

        return $fieldValues ?? [];
    }

    public static function getBranchId(Lead $lead, Contact $contact, Setting $setting)
    {
        $branchId = Branch::query()
            ->where('user_id', $setting->user->id)
            ->orderBy('branch_id')
            ->first()
            ->branch_id;

        $branchValue = self::getFieldBranch($lead, $contact, $setting);

        if ($branchValue) {

            foreach ($setting->user->alfacrm_branches as $branch) {

                if (trim(mb_strtolower($branch->name)) == trim(mb_strtolower($branchValue))) {

                    $branchId = $branch->branch_id;

                    break;
                }
            }
        }

        return $branchId;
    }

    public function customerUpdateOrCreate(array $fieldValues, alfaApi $alfaApi, ?bool $workLead = true)
    {
        $customers = (new Customer($alfaApi))->search($fieldValues['phone']);

        if ($workLead) {

            $customers = (new Customer($alfaApi))->search($fieldValues['phone']);

            if (count($customers) == 0) {

                $customers = (new Customer($alfaApi))->searchLead($fieldValues['phone']);
            } else {
                $fieldValues['is_study'] = 1;
            }
        }

        if (count($customers) == 0) {

            $fieldValues['branch_ids'] = [$fieldValues['branch_id']];

            $fieldValues['study_status_id'] = $workLead ? $fieldValues['stage_id'] : 1;
            $fieldValues['is_study'] = $workLead ? 0 : 1;
            $fieldValues['legal_type'] = 1;

            $customer = (new Customer($alfaApi))->create($fieldValues);

            if (is_string($customer)) return $customer;

        } else {
            $customer = $customers[0];

            $fieldValues['branch_ids'] = array_merge($customer->branch_ids, [$fieldValues['branch_id']]);
        }

        (new Customer($alfaApi))->update($customer->id, $fieldValues);

        return $customer;
    }
}
