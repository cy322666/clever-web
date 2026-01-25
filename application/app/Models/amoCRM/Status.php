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

    public $timestamps = true;

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

    public static function getTriggerPipelines() : array
    {
        return Status::getPipelines()
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

        $pipelines = Status::getPipelines()
            ->get();

        foreach ($pipelines as $pipeline) {

            $statuses = Status::getWithoutUnsorted()
                ->where('pipeline_id', $pipeline->pipeline_id)
                ->get()
                ->sortBy('id')
                ->pluck('name', 'status_id')
                ->toArray();

            foreach ($statuses as $statusId => $statusName) {

                $pipelineArrays[$pipeline->pipeline_name][$pipeline->pipeline_id.'.'.$statusId] = $statusName;
            }
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
