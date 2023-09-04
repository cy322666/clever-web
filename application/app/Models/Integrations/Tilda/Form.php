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
        $arrayCookies = explode('; ', $this->body->COOKIES ?? '');

        $arrayCookies['referrer'] = $this->roistat_url ?? null;

        return  $arrayCookies;
    }

    public function getCustomFields(Lead $lead, $fields) : Lead
    {
        $body = json_decode($this->body);

        foreach ($fields as $field) {

            $fieldName = Field::query()->find($field['field_amo'])->name;

            $lead = Leads::setField($lead, $fieldName, $body->{$field['field_form']});
        }

        return $lead;
    }
}
