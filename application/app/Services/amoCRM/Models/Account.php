<?php

namespace App\Services\amoCRM\Models;

use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Services\amoCRM\Client;
use Illuminate\Support\Facades\Auth;

class Account
{
    public static function users(Client $amoApi): void
    {
        $users = $amoApi->service->account->users;

        foreach ($users as $user) {

            Staff::query()->updateOrCreate([
                'user_id'  => Auth::user()->id,
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

    public static function statuses(Client $amoApi): void
    {
        $pipelines = $amoApi->service->account->pipelines;

        foreach ($pipelines->toArray() as $pipeline) {

            foreach ($pipeline['statuses']->toArray() as $status) {

                Status::query()->updateOrCreate([
                    'user_id'      => Auth::user()->id,
                    'status_id'    => $status['id'],
                ], [
                    'name'         => $status['name'],
                    'is_main'      => $pipeline['is_main'],
//                    'is_archive'   => $status['archive'],//TODO
                    'color'        => $status['color'],
                    'pipeline_id'  => $pipeline['id'],
                    'pipeline_name'=> $pipeline['name'],
                ]);
            }
        }
    }

    /**
     * @throws \Exception
     */
    public static function fields(Client $amoApi): void
    {
        $fields = $amoApi->service
            ->ajax()
            ->get('/api/v4/leads/custom_fields')
            ->_embedded
            ->custom_fields;

        foreach ($fields as $field) {

            Field::query()->updateOrCreate([
                'user_id' => Auth::id(),
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
    }
}
