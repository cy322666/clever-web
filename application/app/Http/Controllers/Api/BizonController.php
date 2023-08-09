<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BizonViewerSend;
use App\Models\Core\Account;
//use App\Models\Integrations\Bizon\BizonDispatcher;
use App\Models\Integrations\Bizon\Setting;
use App\Models\Integrations\Bizon\Viewer;
use App\Models\Integrations\Bizon\Webinar;
use App\Models\User;
use App\Services\Bizon365\Client;
use App\Services\Bizon365\ViewerSender;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        if ($setting->token) {

            $bizon = (new Client())->setToken($setting->token);
        } else
            die('!token');

        $info = $bizon->webinar($webinar->webinarId);

        $webinar->room_title = $info->room_title;
        $webinar->created    = $info->report->created;
        $webinar->group      = $info->report->group;
        $webinar->save();

        $report = json_decode($info->report->report, true);

        $commentariesTS = json_decode($info->report->messages, true);

        foreach ($report['usersMeta'] as $user_key => $user_array) {

            $viewer = $webinar->setViewer($user_key, $user_array, $setting, $commentariesTS);

            BizonViewerSend::dispatch($viewer, $setting, $user->account);
        }
    }
}
