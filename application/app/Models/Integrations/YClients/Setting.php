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
        '6_month'  => '6.000 р',
        '12_month' => '12.000 р',
    ];

    protected $fillable = [
        'active',
        'status_id_cancel',
        'status_id_wait',
        'status_id_came',
        'status_id_confirm',
        'status_id_delete',
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

    public static function YCfieldsSelect(): array
    {
        return [
            'sex' => 'Пол (список М/Ж/строка)',
            'birth_date' => 'Дата рождения (дата)',
            'discount' => 'Скидка (число)',
            'comment' => 'Комментарий (строка)',
            'sms_check' => 'Поздравлять с ДР (флаг/строка)',
            'sms_not' => 'Отправлять рассылку (флаг/строка)',
//            'categories' => 'Категории клиента (строка)',
            'branch' => 'Филиал (список/строка)',

            'visits' => 'Кол-во визитов',
            'staff' => 'Мастер',
            'ltv' => 'Выручка',
            'client_id' => 'ID клиента',
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
        $fields['client_id'] = $record->client->client_id;

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
