<?php

namespace App\Models\Integrations\GetCourse;

use App\Models\amoCRM\Field;
use App\Services\amoCRM\Models\Leads;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ufee\Amo\Models\Lead;

class Form extends Model
{
    use HasFactory;

    const STATUS_WAIT = 0;
    const STATUS_OK   = 1;
    const STATUS_FAIL = 2;

    protected $table = 'getcourse_forms';

    protected $fillable = [
        'email',
        'phone',
        'name',
        'status',
        'user_id',
        'lead_id',
        'contact_id',
        'utm_medium',
        'utm_content',
        'utm_source',
        'utm_term',
        'utm_campaign',
        'user_id',
        'form',
    ];

    public function setCustomFields(Lead $lead, $fields) : Lead
    {
        $body = json_decode($this->body);

        foreach ($fields as $field) {

            $fieldName = Field::query()->find($field['field_amo'])?->name;

            if (!empty($field['field_form']) && !empty($body->{$field['field_form']}))

                $lead = Leads::setField($lead, $fieldName, $body->{$field['field_form']});
        }

        return $lead;
    }
}
