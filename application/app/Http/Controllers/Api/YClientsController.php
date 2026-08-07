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
        Log::info('YClients api логирование', [
            'user_id' => $user->id,
            'resource_id' => $request->input('resource_id'),
            'company_id' => $request->input('company_id'),
            'body' => $request->all(),
        ]);

        $resource = static::resolveResource($request);

        if (!$resource) {
//            Log::warning('YClients webhook skipped: resource is missing', [
//                'user_id' => $user->id,
//                'user_uuid' => $user->uuid,
//                'payload_keys' => array_keys($request->all()),
//                'query_keys' => array_keys($request->query()),
//            ]);

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

        $data = $request->input('data', []);
        $isDeleted = static::isDeletedRecordWebhook($request);
        $clientId = data_get($data, 'client.id');

        if ($clientId) {
            Client::query()
                ->updateOrCreate([
                    'client_id' => $clientId,
                    'company_id' => $request->input('company_id'),
                    'user_id' => $user->id,
                    'setting_id' => $setting->id,
                    'account_id' => $account->id,
                ], [
                    'name' => data_get($data, 'client.name')
                        ?: data_get($data, 'client.display_name'),
                    'phone' => data_get($data, 'client.phone'),
                    'email' => data_get($data, 'client.email'),
                    'visits' => data_get($data, 'client.success_visits_count', 0),
                ]);
        }

        $record = Record::query()->firstOrNew([
            'user_id' => $user->id,
            'record_id' => $request->input('resource_id'),
            'company_id' => $request->input('company_id'),
            'setting_id' => $setting->id,
            'account_id' => $account->id,
        ]);

        $record->fill([
            'status' => Record::STATUS_PENDING,
        ]);

        $record->fill(static::recordAttributes($data, $isDeleted));
        $record->save();

        RecordSend::dispatch($record, $account, $setting, $record->wasRecentlyCreated);

        return response()->json([
            'ok' => true,
            'record_id' => $record->id,
        ], 201);
    }

    private static function isDeletedRecordWebhook(Request $request): bool
    {
        $deleted = data_get($request->input('data', []), 'deleted');

        return strtolower((string)$request->input('status')) === 'delete'
            || in_array($deleted, [true, 1, '1', 'true'], true);
    }

    private static function recordAttributes(array $data, bool $isDeleted): array
    {
        $attributes = [];

        if ($isDeleted) {
            $attributes['attendance'] = 3;
        } elseif (array_key_exists('attendance', $data)) {
            $attributes['attendance'] = data_get($data, 'attendance');
        }

        foreach ([
            'title' => fn(): string => Record::buildCommentServices($data),
            'cost' => fn(): int => Record::sumCostServices($data),
            'staff_id' => fn(): mixed => data_get($data, 'staff_id'),
            'staff_name' => fn(): mixed => data_get($data, 'staff.name'),
            'client_id' => fn(): mixed => data_get($data, 'client.id'),
            'created_user_id' => fn(): mixed => data_get($data, 'created_user_id'),
            'record_from' => fn(): mixed => data_get($data, 'record_from'),
            'create_date' => fn(): mixed => data_get($data, 'create_date'),
            'visit_id' => fn(): mixed => data_get($data, 'visit_id'),
            'datetime' => fn(): string => Carbon::parse(data_get($data, 'datetime') ?: data_get($data, 'date'))
                ->format('Y.m.d H:i:s'),
            'comment' => fn(): mixed => data_get($data, 'comment'),
            'seance_length' => fn(): mixed => data_get($data, 'length'),
        ] as $attribute => $value) {
            if (!static::hasWebhookDataForAttribute($data, $attribute)) {
                continue;
            }

            $attributes[$attribute] = $value();
        }

        return $attributes;
    }

    private static function hasWebhookDataForAttribute(array $data, string $attribute): bool
    {
        return match ($attribute) {
            'title', 'cost' => array_key_exists('services', $data),
            'staff_name' => data_get($data, 'staff.name') !== null,
            'client_id' => data_get($data, 'client.id') !== null,
            'datetime' => data_get($data, 'datetime') !== null || data_get($data, 'date') !== null,
            default => array_key_exists($attribute, $data),
        };
    }
}
