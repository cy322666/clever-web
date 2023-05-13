<?php


namespace App\Models\amoCRM;


use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    protected $fillable = [
        'account_id',
        'name',
        'staff_id',
        'group',
    ];

    protected $table = 'amocrm_staffs';
}
