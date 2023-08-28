<?php


namespace App\Models\amoCRM;


use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Status extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'status_id',
        'pipeline_id',
        'pipeline_name',
        'color',
        'is_main',
    ];

    protected $table = 'amocrm_statuses';

    public $timestamps = false;

    public static function getWithoutUnsorted(): Builder
    {
        return Status::query()
            ->where('user_id', Auth::id())
            ->where('is_archive', false)
            ->where('name', '!=', 'Неразобранное');
    }

    public static function getWithUser(): Builder
    {
        return Status::query()->where('user_id', Auth::id());
    }

    public static function getPipelines(): Builder
    {
        return static::getWithUser()
            ->where('user_id', Auth::id())
            ->where('is_archive', false)
            ->distinct('pipeline_id');
    }
}
