<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\YClients\RecordSend;
use App\Models\Integrations\Tilda\Form;
use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Record;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class YClientsController extends Controller
{
    public function hook(User $user, Request $request)
    {
        if ($request->post('resource')) {

            match ($request->post('resource')) {

                'record' => static::record($user, $request),

                default  => exit,

            };
        }
    }

    public static function record(User $user, Request $request)
    {
        $setting = $user->yclientsSetting;

        $account = $user->account;

        Client::query()
            ->updateOrCreate([
                'client_id'  => $request->data['client']['id'] ?? exit,
                'user_id' => $user->id,
                'setting_id'   => $setting->id,
                'account_id'   => $account->id,
            ],[
                'company_id' => $request->company_id,
                'name'  => $request->data['client']['name'],
                'phone' => $request->data['client']['phone'],
                'email' => $request->data['client']['email'],
                'visits'=> $request->data['client']['success_visits_count'] ?? 0,
            ]);

        $record = Record::query()
            ->create([
                'user_id' => $user->id,
                'record_id'  => $request->resource_id,
                'company_id' => $request->company_id,
                'setting_id'   => $setting->id,
                'account_id'   => $account->id,
                'title' => Record::buildCommentServices($request->data),
                'cost'  => Record::sumCostServices($request->data),
                'staff_id'   => $request->data['staff_id'],
                'staff_name' => $request->data['staff']['name'],
                'client_id'  => $request->data['client']['id'],
                'visit_id'   => $request->data['visit_id'],
                'datetime'   => Carbon::parse($request->data['datetime'])->format('Y.m.d H:i:s'),
                'comment'    => $request->data['comment'],
                'seance_length' => $request->data['length'],
                'attendance' => $request->data['attendance'],
                'status' => 0,
            ]);

        RecordSend::dispatch($record, $account, $setting);
    }
}
