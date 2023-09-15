<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ActiveLead\CheckLead;
use App\Models\Integrations\ActiveLead\Lead;
use App\Models\User;
use Illuminate\Http\Request;

class ActiveLeadController extends Controller
{
    public function hook(User $user, Request $request)
    {
        $data = $request->toArray()['leads']['status'][0] ?? $request->toArray()['leads']['add'][0];

        if (!Lead::query()
            ->where('user_id', $user->id)
            ->where('lead_id', $data['id'])
            ->exists()) {

            $model = Lead::query()->create([
                'user_id' => $user->id,
                'lead_id' => $data['id'],
                'status_id'   => $data['status_id'],
                'pipeline_id' => $data['pipeline_id'],
            ]);

            CheckLead::dispatch($model, $user->activeLeadSetting, $user->account);
        }
    }
}
