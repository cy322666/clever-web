<?php

namespace App\Models\Alfa;

use App\Models\Webhook;
use App\Services\AlfaCRM\Mapper;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'alfacrm_transactions';

    protected $fillable = [
        'user_id',
        'webhook_id',
        'fields',        //поля для отправки в альфу
        'amo_lead_id',
        'alfa_branch_id',
        'alfa_client_id',
        'comment',
        'status',
        'status_id',
        'error',
        'user_id',
    ];

    public function setRecordData(array $data, Webhook $webhook)
    {
        $this->fill([
            'webhook_id'  => $webhook->id,
            'amo_lead_id' => $data['id'],
            'status_id'   => $data['status_id'],
            'comment' => 'created',
            'status'  => Mapper::RECORD,
            'user_id' => $webhook->user->id,
            'error'   => null,
        ]);
        $this->save();
    }

    public function setCameData(array $data, Webhook $webhook)
    {
        $this->fill([
            'webhook_id'  => $webhook->id,
            'comment' => 'came',
            'status'  => Mapper::CAME,
            'error'   => null,
        ]);
        $this->save();
    }

    public function setOmissionData(array $data, Webhook $webhook)
    {
        $this->fill([
            'webhook_id'  => $webhook->id,
            'comment' => 'omission',
            'status'  => Mapper::OMISSION,
            'error'   => null,
        ]);
        $this->save();
    }
}
