<?php


namespace App\Services\amoCRM\Models;

use App\Services\amoCRM\Client;
use Illuminate\Support\Facades\Log;

class Tasks
{
    public static function create($lead, array $parameters, string $text, $type = 1)
    {
        $task = $lead->createTask($type);

        if(!empty($parameters['complete_till_at']))
            $task->complete_till_at = $parameters['complete_till_at'];
        else
            $task->complete_till_at = time() + 60 * 60;

        if(!empty($parameters['responsible_user_id']))
            $task->responsible_user_id = $parameters['responsible_user_id'];

        $task->text = $text;
        $task->save();

        return $task;
    }

    public static function updateResponsible($amoApi, $lead, $responsible_user_id)
    {
        $tasks = self::getAll($lead);

        if($tasks->first()) {

            foreach ($tasks->toArray() as $task) {

                if($task['is_completed'] == false) {

                    $modelTask = $amoApi->service->tasks()->find($task['id']);

                    $modelTask->responsible_user_id = $responsible_user_id;
                    $modelTask->save();

                    Log::info('У сотрудника ('. $responsible_user_id . ') смена в задаче  ('.$modelTask->id. ')');
                }
            }
        }
    }

    public static function getAll($lead)
    {
        return $lead->tasks;
    }
}
