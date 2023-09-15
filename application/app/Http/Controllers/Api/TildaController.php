<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Tilda\FormSend;
use App\Models\Integrations\Tilda\Form;
use App\Models\Integrations\Tilda\Setting;
use App\Models\User;
use Illuminate\Http\Request;

class TildaController extends Controller
{
    public function hook(User $user, string $site, Request $request)
    {
        $setting = $user->tilda_settings;

        $bodies  = json_decode($setting->bodies, true);
        $data    = $request->toArray();

        unset($data['COOKIES']);

        foreach ($data as $key => $value) {

            $data[$key] = '*';
        }

        $bodies[$site] = $data;

        $setting->bodies = json_encode($bodies, true);
        $setting->save();

        if ($request->test == 'test') exit;

        $form = Form::query()->create([
            'user_id' => $user->id,
            'body'    => json_encode($request->toArray(), JSON_UNESCAPED_UNICODE),
            'site'    => $site,
            'status'  => false,
        ]);

        FormSend::dispatch($form, $user->account, $setting);
    }
}
