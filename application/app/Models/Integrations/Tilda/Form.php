<?php

namespace App\Models\Integrations\Tilda;

use App\Models\amoCRM\Field;
use App\Models\User;
use App\Services\amoCRM\Models\Leads;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parseCookies() : array
    {
        $utms = [];

        $body = json_decode($this->body);

        if (!empty($body->COOKIES)) {

            $arrayCookies = explode(';', $body->COOKIES ?? '');

            foreach ($arrayCookies as $cookie) {

                $array = explode('=', $cookie);

                $utms[trim($array[0])] = trim(urldecode($array[1] ?? ''));
            }
        }

        $utms['utm_source'] = $body->utm_source ?? null;
        $utms['utm_medium'] = $body->utm_medium ?? null;
        $utms['utm_campaign'] = $body->utm_campaign ?? null;
        $utms['utm_content'] = $body->utm_content ?? null;
        $utms['utm_term'] = $body->utm_term ?? null;

        $rawUrl = $utms['TILDAUTM'] ?? null;

//        Log::alert(__METHOD__.' id : '.$this->id, ['raw_utm' => $rawUrl]);

        if ($rawUrl) {

            $arrRawUtms = explode('amp;', $rawUrl);

            foreach ($arrRawUtms as $arrRawUtm) {

                $arrUtm = explode('=', $arrRawUtm);

                if (empty($utms[$arrUtm[0]])) {

                    $utms[$arrUtm[0]] = explode('#', $arrUtm[1])[0];

                    $utms[$arrUtm[0]] = str_replace('|||', '', $utms[$arrUtm[0]]);
                }
            }
        }

//        Log::alert(__METHOD__.' id : '.$this->id, ['utms' => $utms]);

        return $utms;
    }

    public static function getValueForKey(string $key, \stdClass $body, array $setting)
    {
        $key   = ucfirst($key);

        $value = !empty($setting[$key]) && !empty($body->{$setting[$key]}) ? $body->{$setting[$key]} : null;

        if (!$value) {

            $key = strtolower($key);

            $value = !empty($setting[$key]) && !empty($body->{$setting[$key]}) ? $body->{$setting[$key]} : null;

            if (!$value) {

                $keySetting = strtolower($setting[$key]);

                $value = !empty($keySetting) && !empty($body->{$keySetting}) ? $body->{$keySetting} : null;
            }
        }
        return $value;
    }

    public function setCustomFields(Lead $lead, $fields) : Lead
    {
        $body = json_decode($this->body);

        foreach ($fields as $field) {

            $fieldName = Field::query()->find($field['field_amo'])?->name;

            if ($field['field_form'] == 'quantity' && !empty($body->payment->products) && is_array($body->payment->products)) {

                $lead = Leads::setField($lead, $fieldName, count($body->payment->products));

            } elseif (!empty($field['field_form']) && !empty($body->{$field['field_form']})) {

                $lead = Leads::setField($lead, $fieldName, $body->{$field['field_form']});
            }
        }

        return $lead;
    }

    public function setCustomFieldsProduct(Lead $lead, $fields) : Lead
    {
        $body = json_decode($this->body)->payment;

        foreach ($fields as $field) {

            $fieldName = Field::query()->find($field['field_amo'])?->name;

            if (!empty($field['field_form']) && !empty($body->{$field['field_form']}))

                $lead = Leads::setField($lead, $fieldName, $body->{$field['field_form']});
        }

        return $lead;
    }
}
