<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Distribution\ResponsibleSend;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\User;
use Illuminate\Http\Request;

class DistributionController extends Controller
{
    public function hook(User $user, string $template, Request $request)
    {
        $setting = $user->distribution_settings;

        $settingTemplate = json_decode($setting->settings, true)[$template];

        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'lead_id' => $request->leads['status'][0]['id'] ?? $request->leads['add'][0]['id'],
            'body'    => json_encode($request->toArray(), JSON_UNESCAPED_UNICODE),
            'type'    => $settingTemplate['strategy'] ?? false,//TODO exception
            'template' => $template,
            'distribution_setting_id' => $setting->id,
            'schedule' => $settingTemplate['schedule'] == 'schedule_yes',
            'status'   => false,
        ]);

        ResponsibleSend::dispatch($transaction->id, $setting->id, $user->id);
    }
}
