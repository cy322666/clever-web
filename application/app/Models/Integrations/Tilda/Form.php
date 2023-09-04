<?php

namespace App\Models\Integrations\Tilda;

use App\Models\amoCRM\Field;
use App\Services\amoCRM\Models\Leads;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ufee\Amo\Models\Lead;

class Form extends Model
{
    use HasFactory;

    protected $table = 'tilda_forms';

    protected $fillable = [
        'body',
        'status',
        'lead_id',
        'contact_id',
        'user_id',
        'site',
    ];

    public function parseCookies() : array
    {
        $utms = [];

        $body = json_decode($this->body);

        $arrayCookies = explode(';', $body->COOKIES ?? '');

        foreach ($arrayCookies as $cookie) {

            $array = explode('=', $cookie);

            $utms[trim($array[0])] = trim(urldecode($array[1]));
        }

        $utms['roistat_url'] = $this->roistat_url;
        $utms['utm_source']  = $this->utm_source;
        $utms['utm_medium']  = $this->utm_medium;
        $utms['utm_content'] = $this->utm_content;
        $utms['utm_term'] = $this->utm_term;
        $utms['utm_campaign'] = $this->utm_campaign;

        return $utms;
    }

    public function setCustomFields(Lead $lead, $fields) : Lead
    {
        $body = json_decode($this->body);

        foreach ($fields as $field) {

            $fieldName = Field::query()->find($field['field_amo'])->name;

            $lead = Leads::setField($lead, $fieldName, $body->{$field['field_form']});
        }

        return $lead;
    }
}
