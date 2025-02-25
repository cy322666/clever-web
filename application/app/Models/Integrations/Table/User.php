<?php

namespace App\Models\Integrations\Table;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use HasFactory;

    protected $table = 'table_users';

    protected $fillable = [
        'user_id',
        'username',
        'body',
        'base_filename',
        'lead_id',
        'contact_id',
        'status',
        'base_id',
        'table_setting_id',
    ];
}
