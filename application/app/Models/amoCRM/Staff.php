<?php


namespace App\Models\amoCRM;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Staff extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'staff_id',
        'group',
    ];

    protected $table = 'amocrm_staffs';

    public static function getWithUser(): Builder
    {
        return Staff::query()->where('user_id', Auth::id());
    }
}
