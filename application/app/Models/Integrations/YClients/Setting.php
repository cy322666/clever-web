<?php

namespace App\Models\Integrations\YClients;

use App\Helpers\Traits\SettingRelation;
use App\Models\amoCRM\Field;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Contacts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;
use Vgrish\Yclients\Yclients;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'yclients_settings';

    //TODO используется?
    public const CREATED = 0;
    public const RECORD = 1;
    public const CAME = 2;
    public const OMISSION = 3;

    static array $cost = [
        '6_month'  => '6.000 р',
        '12_month' => '10.000 р',
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
            'sex' => 'Пол (список М/Ж)',
            'birth_date' => 'Дата рождения (дата)',
            'discount' => 'Скидка (число)',
            'comment' => 'Комментарий (строка)',
            'sms_check' => 'Поздравлять с ДР (флаг)',
            'sms_not' => 'Отправлять рассылку (флаг)',
            'categories' => 'Категории клиента (строка)',
            'title' => 'Филиал (список/строка)',
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
            'categories',
            'title',
        ];
    }

    public static function YCGetFields(Yclients $client, Record $record): array
    {
        $fields = static::YCfields();

        $clientYC = $client->query()
            ->client()
            ->path('company_id', $client->company_id)
            ->path('id', $client->client_id)
            ->get();

        $categories = '';

        if (count($clientYC->object()->getCategories()) > 0) {
            if (is_array($clientYC->object()->getCategories())) {
                foreach ($clientYC->object()->getCategories() as $category) {
                    $categories .= $category->title . ', ';
                }
                $categories = str_replace(',', '', $categories);
            }
        }

        $fields['branch'] = $record->getBranchTitle($client);

        $fields['branches'] = $client->query()->state()->getData();

        $fields['categories'] = $categories;

        $fields['sex'] = match ($clientYC->object()->getSex()) {
            'Женский' => 'Ж',
            'Мужской' => 'М',
            default => null,
        };

        $fields['birth_date'] = $clientYC->object()->getBirthDate() ? $clientYC->object()->getBirthDate() : null;

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

                $contact = Contacts::setField($contact, $fieldName, $ycFields['field_yc']);
        }
        $contact->save();
    }

    public function YCSetLeadFields(Lead $lead, array $ycFields): Lead
    {
        $body = json_decode($this->fields_contact, true);

        foreach ($body as $field) {

            $fieldName = Field::query()->find($field['field_amo'])?->name;

            if ($fieldName)

                $lead = Leads::setField($lead, $fieldName, $ycFields['field_yc']);
        }
        $lead->save();
    }
}
