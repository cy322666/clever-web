<?php

namespace App\Services\amoCRM\Models;

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
                'name'     => $user->name,
                'group'    => $user->group->id,
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
                    'color'        => $status['color'],
                    'pipeline_id'  => $pipeline['id'],
                    'pipeline_name'=> $pipeline['name'],
                ]);
            }
        }
    }

    public function pipelines(Client $amoApi)
    {

    }
}
