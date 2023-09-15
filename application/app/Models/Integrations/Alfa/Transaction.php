<?php

namespace App\Models\Integrations\Alfa;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'alfacrm_transactions';

    protected $fillable = [
        'user_id',
        'webhook_id',
        'fields',        //поля для отправки в альфу
        'amo_lead_id',
        'amo_contact_id',
        'alfa_branch_id',
        'alfa_client_id',
        'comment',
        'status',
        'status_id',
        'error',
        'user_id',
    ];
}
