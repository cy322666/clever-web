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
        $model = Lead::query()->create($request->toArray());

        CheckLead::dispatch($model, $user->activeLeadSetting, $user->account);
    }
}
