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
use Illuminate\Support\Facades\Log;

class YClientsController extends Controller
{
    public function hook(User $user, Request $request): JsonResponse
    {
        $resource = static::resolveResource($request);

        Log::info('YClients api логирование', [
            'user_id' => $user->id,
            'user_uuid' => $user->uuid,
            'resource' => $resource,
            'resource_id' => $request->input('resource_id'),
            'company_id' => $request->input('company_id'),
            'payload_keys' => array_keys($request->all()),
            'query_keys' => array_keys($request->query()),
        ]);

        if (!$resource) {
            Log::warning('YClients webhook skipped: resource is missing', [
                'user_id' => $user->id,
                'user_uuid' => $user->uuid,
                'payload_keys' => array_keys($request->all()),
                'query_keys' => array_keys($request->query()),
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'ignored: resource is required',
            ], 202);
        }

        return match ($resource) {
            'record' => static::record($user, $request),
            default => response()->json([
                'ok' => false,
                'message' => 'unsupported resource',
            ], 422),
        };
    }

    private static function resolveResource(Request $request): ?string
    {
        $resource = $request->input('resource');

        if (is_string($resource) && trim($resource) !== '') {
            return strtolower(trim($resource));
        }

        if ($request->filled('resource_id') && $request->filled('company_id') && $request->has('data')) {
            return 'record';
        }

        return null;
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

        if ($clientId) {
            Client::query()
                ->updateOrCreate([
                    'client_id' => $clientId,
                    'company_id' => $request->company_id,
                    'user_id' => $user->id,
                    'setting_id' => $setting->id,
                    'account_id' => $account->id,
                ], [
                    'name' => data_get($request->data, 'client.name')
                        ?: data_get($request->data, 'client.display_name'),
                    'phone' => data_get($request->data, 'client.phone'),
                    'email' => data_get($request->data, 'client.email'),
                    'visits' => data_get($request->data, 'client.success_visits_count', 0),
                ]);
        }

        $record = Record::query()->updateOrCreate([
            'user_id' => $user->id,
            'record_id' => $request->resource_id,
            'company_id' => $request->company_id,
            'setting_id' => $setting->id,
            'account_id' => $account->id,
        ], [
            'title' => Record::buildCommentServices($request->data),
            'cost' => Record::sumCostServices($request->data),
            'staff_id' => data_get($request->data, 'staff_id'),
            'staff_name' => data_get($request->data, 'staff.name'),
            'client_id' => $clientId,
            'created_user_id' => data_get($request->data, 'created_user_id'),
            'record_from' => data_get($request->data, 'record_from'),
            'create_date' => data_get($request->data, 'create_date'),
            'visit_id' => data_get($request->data, 'visit_id'),
            'datetime' => Carbon::parse(data_get($request->data, 'datetime'))->format('Y.m.d H:i:s'),
            'comment' => data_get($request->data, 'comment'),
            'seance_length' => data_get($request->data, 'length'),
            'attendance' => data_get($request->data, 'attendance'),
            'status' => Record::STATUS_PENDING,
        ]);

        RecordSend::dispatch($record, $account, $setting, $record->wasRecentlyCreated);

        return response()->json([
            'ok' => true,
            'record_id' => $record->id,
        ], 201);
    }
}
