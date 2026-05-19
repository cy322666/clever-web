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
            'branch' => self::fieldLabel('Филиал', 'branch', 'список/строка'),
            'company_id' => self::fieldLabel('ID филиала', 'company_id'),
            'record_id' => self::fieldLabel('ID записи', 'record_id'),

            'visits' => self::fieldLabel('Кол-во визитов', 'visits'),
            'staff' => self::fieldLabel('Мастер', 'staff'),
            'ltv' => self::fieldLabel('Выручка', 'ltv'),
            'client_id' => self::fieldLabel('ID клиента', 'client_id'),
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

            'visits',
            'staff',
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
