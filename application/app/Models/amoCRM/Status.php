<?php


namespace App\Models\amoCRM;


use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'status_id',
        'pipeline_id',
        'pipeline_name',
        'color',
    ];

    protected $table = 'amocrm_statuses';

    public $timestamps = false;
}
