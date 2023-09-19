<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Bizon\FormSend;
use App\Jobs\Bizon\ViewerSend;
use App\Models\Integrations\Bizon\Form;
use App\Models\User;
use App\Services\Bizon365\Client;
use Exception;
use Filament\Notifications\Notification;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BizonController extends Controller
{
    public function form(User $user, Request $request)
    {
        $form = Form::query()->create([

        ]);

        FormSend::dispatch($form, $user->bizon_settings, $user->account);
    }

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

        $amoApi = (new \App\Services\amoCRM\Client($user->account));

//        if (!$amoApi->auth) {
//
//            Notification::make()
//                ->title('Зрители вебинара не выгружены из-за ошибки авторизации в amoCRM')
//                ->danger()
//                ->sendToDatabase($user);
//
//            return;
//        }

        $delay = 0;

        foreach ($report['usersMeta'] as $userKey => $userArray) {

            $viewer = $webinar->setViewer($userKey, $userArray, $setting, $commentariesTS);

            ViewerSend::dispatch($viewer, $setting, $user->account)->delay(++$delay);
        }
    }
}
