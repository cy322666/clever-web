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
        'sort',
        'is_main',
        'is_closed',
        'is_won',
        'is_lost',
        'active',
    ];

    protected $table = 'amocrm_statuses';

    public $timestamps = true;

    public static function getWithoutUnsorted(): Builder
    {
        return Status::query()
            ->where('user_id', Auth::id())
            ->where('active', true)
            ->where('is_archive', false)
            ->where('name', '!=', 'Неразобранное');
    }

    public static function getWithUser(): Builder
    {
        return Status::query()
            ->where('active', true)
            ->where('user_id', Auth::id());
    }

    public static function getPipelines(): Builder
    {
        return static::getWithUser()
            ->where('user_id', Auth::id())
            ->where('active', true)
            ->where('is_archive', false)
            ->distinct('pipeline_id');
    }

    public static function getTriggerPipelines() : array
    {
        return Status::getPipelines()
            ->where('active', true)
            ->get()
            ->pluck('pipeline_name', 'pipeline_id')
            ->toArray();
    }

    /*
     *   "Первичные продажи" => array:8 [▼
     *        "3230029.32756548" => "Новый лид"
     */
    public static function getTriggerStatuses() : array
    {
        $pipelineArrays = [];

        $statuses = Status::getWithoutUnsorted()
            ->where('active', true)
            ->orderBy('pipeline_name')
            ->orderBy('id')
            ->get();

        foreach ($statuses as $status) {
            $pipelineArrays[$status->pipeline_name][$status->pipeline_id.'.'.$status->status_id] = $status->name;
        }

        return $pipelineArrays;
    }

    // "3230029.32756548"
    public static function getObject(?string $pStatusId): object
    {
        if (is_string($pStatusId)) {

            $array = explode('.', $pStatusId);

            return (object)[
                'status_id'   => $array[1],
                'pipeline_id' => $array[0],
            ];

        } else
            return
                (object)[
                    'status_id',
                    'pipeline_id',
                ];
    }
}
