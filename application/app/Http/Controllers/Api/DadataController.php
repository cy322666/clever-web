<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Dadata\InfoLead;
use App\Models\Integrations\Dadata\Lead;
use App\Models\User;
use Illuminate\Http\Request;

class DadataController extends Controller
{
    public function hook(User $user, Request $request)
    {
        $setting = $user->dataSetting;
        $account = $setting?->amoAccount(false, 'data-info');

        if (!$setting || !$account) {
            return;
        }

        $data = Lead::query()->create([
            'user_id' => $user->id,
            'lead_id' => $request->leads['add'][0]['id'] ?? $request->leads['status'][0]['id']
        ]);

        InfoLead::dispatch($data, $setting, $account);
    }
}
