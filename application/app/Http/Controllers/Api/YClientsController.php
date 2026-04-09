<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\YClients\RecordSend;
use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Record;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YClientsController extends Controller
{
    public function hook(User $user, Request $request): JsonResponse
    {
        if (!$request->post('resource')) {
            return response()->json([
                'ok' => false,
                'message' => 'resource is required',
            ], 422);
        }

        return match ($request->post('resource')) {
            'record' => static::record($user, $request),
            default => response()->json([
                'ok' => false,
                'message' => 'unsupported resource',
            ], 422),
        };
    }

    public static function record(User $user, Request $request): JsonResponse
    {
        $setting = $user->yclientsSetting;
        $account = $setting?->amoAccount(false, 'yclients');

        if (!$setting || !$account) {
            return response()->json([
                'ok' => false,
                'message' => 'amoCRM account is not configured for yclients',
            ], 422);
        }

        $clientId = data_get($request->data, 'client.id');

        if (!$clientId) {
            return response()->json([
                'ok' => false,
                'message' => 'client.id is required',
            ], 422);
        }

        Client::query()
            ->updateOrCreate([
                'client_id' => $clientId,
                'company_id' => $request->company_id,
                'user_id' => $user->id,
                'setting_id'   => $setting->id,
                'account_id'   => $account->id,
            ],[
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
                'status' => Record::STATUS_PENDING,
            ]);

        RecordSend::dispatch($record, $account, $setting);

        return response()->json([
            'ok' => true,
            'record_id' => $record->id,
        ], 201);
    }
}
