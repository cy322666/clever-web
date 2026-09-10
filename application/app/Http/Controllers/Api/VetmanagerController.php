<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Vetmanager\SyncVisit;
use App\Models\Integrations\Vetmanager\Visit;
use App\Models\User;
use App\Services\Vetmanager\WebhookPayloadNormalizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class VetmanagerController extends Controller
{
    public function hook(
        User $user,
        Request $request,
        WebhookPayloadNormalizer $normalizer,
    ): JsonResponse {
        $setting = $user->vetmanagerSetting()->first();

        if (! $setting || ! $setting->active) {
            return response()->json(['ok' => false, 'message' => 'integration is not configured'], 409);
        }

        $payload = $request->all();

        if ($payload === [] && is_array($request->json()->all())) {
            $payload = $request->json()->all();
        }

        $event = $normalizer->normalize($payload);
        $receivedSecret = trim((string) ($event['params']['dop_param1'] ?? ''));

        if ($receivedSecret === '' || ! hash_equals((string) $setting->webhook_secret, $receivedSecret)) {
            return response()->json(['ok' => false, 'message' => 'invalid webhook secret'], 403);
        }

        if (! $normalizer->isSupported($event['event_name'])) {
            return response()->json(['ok' => true, 'message' => 'event ignored'], 202);
        }

        if ($event['admission_id'] === '') {
            return response()->json(['ok' => false, 'message' => 'admission id is required'], 422);
        }

        $account = $setting->account()->first();

        if (! $account?->active) {
            return response()->json(['ok' => false, 'message' => 'amoCRM account is not configured'], 422);
        }

        $data = $event['data'];
        $identity = [
            'setting_id' => $setting->id,
            'external_id' => $event['admission_id'],
        ];
        $attributes = (new Visit)->forceFill([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'event_name' => $event['event_name'],
            'status' => Visit::STATUS_PENDING,
            'vetmanager_status' => $this->stringOrNull($data['status'] ?? null),
            'client_id' => $this->stringOrNull($data['client_id'] ?? null),
            'patient_id' => $this->stringOrNull($data['patient_id'] ?? null),
            'clinic_id' => $this->stringOrNull($data['clinic_id'] ?? null),
            'admission_date' => $this->dateOrNull($data['admission_date'] ?? null, (string) $setting->timezone),
            'amount' => $this->numberOrNull($data['invoices_sum'] ?? null),
            'event_payload' => $event['event_payload'],
            'error_message' => null,
            'processed_at' => null,
        ])->getAttributes();

        Visit::query()->upsert(
            [array_merge($identity, $attributes)],
            ['setting_id', 'external_id'],
            array_keys($attributes),
        );

        $visit = Visit::query()->where($identity)->firstOrFail();

        SyncVisit::dispatch($visit->id);

        return response()->json([
            'ok' => true,
            'visit_id' => $visit->id,
        ], 202);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function numberOrNull(mixed $value): ?float
    {
        $value = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) $value);
        $value = str_replace(',', '.', (string) $value);

        return is_numeric($value) ? (float) $value : null;
    }

    private function dateOrNull(mixed $value, string $timezone): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone ?: 'Europe/Moscow');
        } catch (Throwable) {
            return null;
        }
    }
}
