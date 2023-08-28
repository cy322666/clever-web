<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Bizon\ViewerSend;
use App\Models\User;
use App\Services\Bizon365\Client;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BizonController extends Controller
{
    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function hook(User $user, Request $request)
    {
        $webinar = $user->webinars()->create($request->toArray());

        $setting = $user->bizon_settings;

        $bizon = (new Client())->setToken($setting->token);

        $info = $bizon->webinar($webinar->webinarId);

        if ($info->report == null) {

            Log::error(__METHOD__, ['!Bizon report', ['user' => $user->id]]);

            return;
        }

        $webinar->room_title = $info->room_title;
        $webinar->created    = $info->report->created;
        $webinar->group      = $info->report->group;
        $webinar->save();

        $report = json_decode($info->report->report, true);

        $commentariesTS = json_decode($info->report->messages, true);

        $amoApi = (new \App\Services\amoCRM\Client($user->account))->init();

        foreach ($report['usersMeta'] as $userKey => $userArray) {

            $viewer = $webinar->setViewer($userKey, $userArray, $setting, $commentariesTS);

            ViewerSend::dispatch($viewer, $setting, $user->account);
        }
    }
}
