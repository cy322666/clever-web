<?php

namespace App\Models\Integrations\GetCourse;

use App\Models\amoCRM\Field;
use App\Models\User;
use App\Services\amoCRM\Models\Leads;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Ufee\Amo\Models\Lead;

class Order extends Model
{
    use HasFactory;

    const STATUS_WAIT = 0;
    const STATUS_OK   = 1;
    const STATUS_FAIL = 2;

    protected $table = 'getcourse_orders';

    protected $fillable = [
        'phone',
        'email',
        'name',
        'number',
        'order_id',
        'positions',
        'left_cost_money',
        'cost_money',
        'payed_money',
        'link',
        'status',
        'status_order',
        'user_id',
        'lead_id',
        'contact_id',
        'user_id',
        'template',
    ];

    public function setCustomFields(Lead $lead, $fields) : Lead
    {
        foreach ($fields as $field) {

            $fieldName = Field::query()->find($field['field_amo'])?->name;

            if (!empty($field['field_form']) && !empty($this->{$field['field_form']}))

                $lead = Leads::setField($lead, $fieldName, $this->{$field['field_form']});
        }

        return $lead;
    }
    public function setting(): HasOne
    {
        return $this->hasOne(Setting::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
