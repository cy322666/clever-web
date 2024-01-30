<?php

namespace App\Services\amoCRM\Models;

use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\User;
use App\Services\amoCRM\Client;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Account
{
    public static function users(Client $amoApi, $userModel): void
    {
        $users = $amoApi->service->account->users;

        foreach ($users as $user) {

            Staff::query()->updateOrCreate([
                'user_id'  => $userModel->id,
                'staff_id' => $user->id,
            ], [
                'group_id'   => $user->group->id,
                'group_name' => $user->group->name,
                'name'     => $user->name,
                'active'   => $user->is_active,
                'login'    => $user->login,
                'phone'    => $user->phone,
                'admin'    => $user->is_admin,
            ]);
        }
    }

    /**
     * @throws Exception
     */
    public static function statuses(Client $amoApi, $user): void
    {
        $pipelines = $amoApi->service ->ajax()
            ->get('/api/v4/leads/pipelines')
            ->_embedded
            ->pipelines;

        foreach ($pipelines as $pipeline) {

            if (!$pipeline->is_archive) {

                foreach ($pipeline->_embedded->statuses as $status) {

                    //TODO del deleted
                    Status::query()->updateOrCreate([
                        'user_id'      => $user->id,
                        'status_id'    => $status->id,
                    ], [
                        'name'         => $status->name,
                        'is_main'      => $pipeline->is_main,
                        'color'        => $status->color,
                        'pipeline_id'  => $pipeline->id,
                        'pipeline_name'=> $pipeline->name,
                    ]);
                }
            }
        }

//        Auth::user()
//            ->amocrm_statuses()
//            ->where('updated_at', '<', Carbon::now()->subSeconds(30)->format('Y-m-d H:i:s'))
//            ->delete();
    }

    /**
     * @throws Exception
     */
    public static function fields(Client $amoApi, $user): void
    {
        for($i = 1 ;; $i++) {

            $fields = $amoApi->service
                ->ajax()
                ->get('/api/v4/leads/custom_fields', ['page' => $i])
                ->_embedded
                ->custom_fields ?? false;

            if (!is_bool($fields))

                foreach ($fields as $field) {

                    Field::query()->updateOrCreate([
                        'user_id' => $user->id,
                        'field_id' => $field->id,
                    ], [
                        'name' => $field->name,
                        'type' => $field->type,
                        'code' => $field->code,
                        'sort' => $field->sort,
                        'is_api_only' => $field->is_api_only,
                        'entity_type' => $field->entity_type,
                        'enums' => json_encode($field->enums, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            else
                break;
        }

        $fields = $amoApi->service
            ->ajax()
            ->get('/api/v4/contacts/custom_fields')
            ->_embedded
            ->custom_fields;

        foreach ($fields as $field) {

            Field::query()->updateOrCreate([
                'user_id' => $user->id,
                'field_id' => $field->id,
            ], [
                'name' => $field->name,
                'type' => $field->type,
                'code' => $field->code,
                'sort' => $field->sort,
                'is_api_only' => $field->is_api_only,
                'entity_type' => $field->entity_type,
                'enums' => json_encode($field->enums, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $fields = $amoApi->service
            ->ajax()
            ->get('/api/v4/companies/custom_fields')
            ->_embedded
            ->custom_fields;

        foreach ($fields as $field) {

            Field::query()->updateOrCreate([
                'user_id' => $user->id,
                'field_id' => $field->id,
            ], [
                'name' => $field->name,
                'type' => $field->type,
                'code' => $field->code,
                'sort' => $field->sort,
                'is_api_only' => $field->is_api_only,
                'entity_type' => $field->entity_type,
                'enums' => json_encode($field->enums, JSON_UNESCAPED_UNICODE),
            ]);
        }

//        Auth::user()
//            ->amocrm_fields()
//            ->where('updated_at', '<', Carbon::now()->subMinute()->format('Y-m-d H:i:s'))
//            ->delete();
    }
}
