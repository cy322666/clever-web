<?php

namespace App\Models\Integrations\YClients;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\amoCRM\Field;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Contacts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'yclients_settings';

    public static string $resource = YClientsResource::class;

    static array $cost = [
        '1_month' => '2 990 ₽',
        '6_month' => '14 900 ₽',
        '12_month' => '24 900 ₽',
    ];

    protected $fillable = [
        'active',
        'status_id_cancel',
        'status_id_wait',
        'status_id_came',
        'status_id_confirm',
        'status_id_delete',
        'pipelines',
        'user_token',
        'partner_token',
        'login',
        'password',
        'branches',
        'user_id',
        'account_id',
        'fields_contact',
        'fields_lead',
    ];

    protected $casts = [
        'pipelines' => 'array',
    ];

    private static function fieldLabel(string $title, string $key, ?string $description = null): string
    {
        $label = $title . ' (' . $key . ')';

        if ($description) {
            $label .= ' - ' . $description;
        }

        return $label;
    }

    private static function humanFieldLabel(string $title, ?string $description = null): string
    {
        return $description ? $title . ' - ' . $description : $title;
    }

    private static function createdUserRoleTitle(?string $role): ?string
    {
        return match ($role) {
            'owner' => 'Владелец',
            'worker' => 'Сотрудник',
            'client' => 'Клиент',
            default => $role ? ucfirst($role) : null,
        };
    }

    public static function YCfieldsSelect(): array
    {
        return [
            'sex' => self::fieldLabel('Пол', 'sex', 'список М/Ж/строка'),
            'birth_date' => self::fieldLabel('Дата рождения', 'birth_date', 'дата'),
            'discount' => self::fieldLabel('Скидка', 'discount', 'число'),
            'comment' => self::fieldLabel('Комментарий', 'comment', 'строка'),
            'sms_check' => self::fieldLabel('Поздравлять с ДР', 'sms_check', 'флаг/строка'),
            'sms_not' => self::fieldLabel('Отправлять рассылку', 'sms_not', 'флаг/строка'),
//            'categories' => 'Категории клиента (строка)',
            'branch' => self::humanFieldLabel('Филиал'),
            'company_id' => self::humanFieldLabel('Филиал записи'),
            'record_id' => self::humanFieldLabel('Запись'),
            'created_user_role_name' => self::humanFieldLabel('Роль создателя'),
            'created_user_department' => self::humanFieldLabel('Отдел создателя'),

            'visits' => self::fieldLabel('Кол-во визитов', 'visits'),
            'staff' => self::fieldLabel('Мастер', 'staff'),
            'paid' => self::fieldLabel('Сумма покупок', 'paid'),
            'ltv' => self::fieldLabel('Выручка', 'ltv'),
            'client_id' => self::humanFieldLabel('Клиент'),
        ];
    }

    public static function YCfields(): array
    {
        return [
            'sex',
            'birth_date',
            'discount',
            'comment',
            'sms_check',
            'sms_not',
//            'categories',
            'branch',
            'company_id',
            'record_id',
            'created_user_role_name',
            'created_user_department',

            'visits',
            'staff',
            'paid',
            'ltv',
            'client_id',
        ];
    }

    /**
     * @throws ConnectionException
     */
    public static function YCGetFields(\App\Services\YClients\YClients $client, Record $record): array
    {
        $fields = static::YCfields();

        $clientYC = $client->getClient($record->company_id, $record->client_id)->data;

//        $categories = '';
//
//        if (count($clientYC->object()->getCategories()) > 0) {
//            if (is_array($clientYC->object()->getCategories()) && count($clientYC->object()->getCategories())) {
//                foreach ($clientYC->object()->getCategories() as $category) {
//
//                    $categories .= $category['title'] ?? null . ', ';
//                }
//                $categories = str_replace(',', '', $categories);
//            }
//        }

        $fields['branch'] = $client->getBranchTitle($record->company_id);
        $fields['company_id'] = $record->company_id;
        $fields['record_id'] = $record->record_id;
        $fields['created_user_role_name'] = null;
        $fields['created_user_department'] = null;

        if (!empty($record->created_user_id)) {
            $createdUser = $client->getUserPermissions($record->company_id, $record->created_user_id);

            $role = data_get($createdUser, 'data.user_role');
            $fields['created_user_role_name'] = self::createdUserRoleTitle($role) ?: 'Сотрудник';

            $staffId = data_get($createdUser, 'data.staff_id');
            $staff = null;

            if (!empty($staffId)) {
                $staff = $client->getStaff($record->company_id, $staffId);
            }

            if (!$staff) {
                $staff = $client->findStaffByUserId($record->company_id, $record->created_user_id);
            }

            $fields['created_user_department'] = data_get($staff, 'data.position.title')
                ?: data_get($staff, 'position.title')
                    ?: data_get($staff, 'data.specialization')
                        ?: self::createdUserRoleTitle($role)
                            ?: 'Сотрудник';
        }

        // $fields['branches'] = $client->query()->state()->getData();

//        $fields['categories'] = $categories;

        $fields['sex'] = match ($clientYC->sex) {
            'Женский' => 'Ж',
            'Мужской' => 'М',
            default => null,
        };

        $fields['birth_date'] = $clientYC->birth_date;

        $fields['visits'] = $clientYC->visits;
        $fields['staff'] = $record->staff_name;
        $fields['paid'] = $clientYC->paid;
        $fields['ltv'] = $clientYC->paid;
        $fields['client_id'] = $record->client_id;

        return $fields;
    }

    public function YCSetContactFields(Contact $contact, array $ycFields): Contact
    {
        $body = json_decode($this->fields_contact, true);

        //field_yc - key array yc
        //field_amo - id
        foreach ($body as $field) {

            $fieldName = Field::query()->find($field['field_amo'])?->name;

            if ($fieldName)
                $contact = Contacts::setField($contact, $fieldName, $ycFields[$field['field_yc']] ?? null);
        }
        $contact->save();

        return $contact;
    }

    public function YCSetLeadFields(Lead $lead, array $ycFields): Lead
    {
        $body = json_decode($this->fields_lead, true);

        if (!$body) {
            return $lead;
        }

        foreach ($body as $field) {

            $fieldName = Field::query()->find($field['field_amo'])?->name;

            if ($fieldName) {
                $lead = Leads::setField($lead, $fieldName, $ycFields[$field['field_yc']] ?? null);
            }
        }
        $lead->save();

        return $lead;
    }
}
