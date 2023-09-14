<?php

namespace App\Models\Integrations\Dadata;

use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ufee\Amo\Models\Contact;

class Lead extends Model
{
    use HasFactory;

    protected $table = 'data_leads';

    protected $fillable = [
        'user_id',
        'status',
        'lead_id',
        'contact_id',
        'source',
        'type',
        'phone_at',
        'phone',
        'country_code',
        'city_code',
        'number',
        'extension',
        'provider',
        'country',
        'region',
        'city',
        'timezone',
        'qc_conflict',
        'qc',
    ];

    public static function setFields(array $fields, \Ufee\Amo\Models\Lead &$lead, Contact &$contact, \App\Models\Integrations\Dadata\Lead $dataModel)
    {
        foreach ($fields as $fieldKey => $fieldModel) {

            if ($fieldModel) {

                /* example: field_country -> country */
                $dataModelKey = explode('_', $fieldKey)[1];

                if ($fieldModel->entity_type == 'leads')

                    $lead = Leads::setField($lead, $fieldModel->name, $dataModel->{$dataModelKey});

                elseif ($fieldModel->entity_type == 'contacts')

                    $contact = Contacts::setField($contact, $fieldModel->name, $dataModel->{$dataModelKey});
            }
        }

//        return [
//            'lead' => $lead,
//            'contact' => $contact,
//        ];
    }
}
