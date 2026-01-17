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
    public static function users(Client $amoApi, User $userModel): void
    {
//        Staff::query()
//            ->where('user_id', $userModel->id)
//            ->delete();

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
    public static function statuses(Client $amoApi, User $user): void
    {
//        Status::query()
//            ->where('user_id', $user->id)
//            ->delete();

        $pipelines = $amoApi->service ->ajax()
            ->get('/api/v4/leads/pipelines')
            ->_embedded
            ->pipelines;

        foreach ($pipelines as $pipeline) {

            if (!$pipeline->is_archive) {

                foreach ($pipeline->_embedded->statuses as $status) {

                    Status::query()->updateOrCreate([
                        'user_id'      => $user->id,
                        'status_id'    => $status->id,
                        'pipeline_id'  => $pipeline->id,
                    ], [
                        'name'         => $status->name,
                        'is_main'      => $pipeline->is_main,
                        'color'        => $status->color,
                        'pipeline_name'=> $pipeline->name,
                    ]);
                }
            }
        }
    }

    /**
     * @throws Exception
     */
    public static function fields(Client $amoApi, $user): void
    {
//        Field::query()
//            ->where('user_id', $user->id)
//            ->delete();

        for($i = 1 ; ; $i++) {

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

            Field::query()->create([
                'user_id' => $user->id,
                'field_id' => $field->id,
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

            Field::query()->create([
                'user_id' => $user->id,
                'field_id' => $field->id,
                'name' => $field->name,
                'type' => $field->type,
                'code' => $field->code,
                'sort' => $field->sort,
                'is_api_only' => $field->is_api_only,
                'entity_type' => $field->entity_type,
                'enums' => json_encode($field->enums, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
}
