<?php

namespace App\Models\Integrations\Alfa;

use App\Services\amoCRM\Models\Contacts;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Field extends Model
{
    protected $table = 'alfacrm_fields';

    protected $fillable = [
        'account_id',
        'entity',
        'name',
        'code',
        'required',
    ];

    public static function prepareCreateCustomer(&$fieldValues, $amoApi, $alfaApi, $contact)
    {
        $fieldValues['web'][] = Contacts::buildLink($amoApi, $contact->id);
        $fieldValues['branch_id']  = $alfaApi->branchId;//TODO бренчи затирает UDP проверить поправил
        $fieldValues['is_study']   = 1;
        $fieldValues['legal_type'] = 1;

        if (!empty($fieldValues['dob'])) {

            $fieldValues['dob'] = Carbon::parse($fieldValues['dob'])->format('d.m.Y');
        }
    }

    public static function prepareCreateLead(&$fieldValues, $amoApi, $alfaApi, $contact, $stageId)
    {
        $fieldValues['lead_status_id'] = $stageId;
        $fieldValues['web'][] = Contacts::buildLink($amoApi, $contact->id);
        $fieldValues['branch_id']  = $alfaApi->branchId;//TODO бренчи затирает все еще?
        $fieldValues['is_study']   = 0;
        $fieldValues['legal_type'] = 1;

        if (!empty($fieldValues['dob'])) {

            $fieldValues['dob'] = Carbon::parse($fieldValues['dob'])->format('d.m.Y');
        }
    }
}
