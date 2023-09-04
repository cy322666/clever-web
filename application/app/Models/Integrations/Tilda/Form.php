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

        $utms['roistat_url'] = $body->roistat_url ?? null;
        $utms['utm_source']  = $body->utm_source ?? null;
        $utms['utm_medium']  = $body->utm_medium ?? null;
        $utms['utm_content'] = $body->utm_content ?? null;
        $utms['utm_term'] = $body->utm_term ?? null;
        $utms['utm_campaign'] = $body->utm_campaign ?? null;

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