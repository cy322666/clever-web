<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Distribution\ResponsibleSend;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DistributionController extends Controller
{
    public function hook(User $user, string $template, Request $request)
    {
        $setting = $user->distribution_settings;
        if (!$setting) {
            return response()->json([
                'ok' => false,
                'message' => 'Distribution setting not found',
            ], 404);
        }

        $settings = json_decode($setting->settings ?? '[]', true);
        $settings = is_array($settings) ? $settings : [];

        [$settingTemplate, $templateIndex, $queueUuid] = $this->resolveTemplate($settings, $template);
        if ($settingTemplate === null) {
            Log::warning('Distribution webhook: queue template not found', [
                'user_id' => $user->id,
                'distribution_setting_id' => $setting->id,
                'template_param' => $template,
                'settings_count' => is_array($settings) ? count($settings) : 0,
            ]);

            return response()->json([
                'ok' => false,
                'code' => 'queue_template_not_found',
                'message' => 'Queue template not found',
            ], 422);
        }

        $payload = $this->resolvePayload($request);
        $leadId = $this->resolveLeadId($payload);
        if ($leadId === null) {
            Log::warning('Distribution webhook: lead id is required', [
                'user_id' => $user->id,
                'distribution_setting_id' => $setting->id,
                'template_param' => $template,
                'payload_keys' => array_keys($payload),
                'leads_type' => gettype($payload['leads'] ?? null),
            ]);

            return response()->json([
                'ok' => false,
                'code' => 'lead_id_required',
                'message' => 'Lead id is required',
            ], 422);
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eventKey = hash('sha256', implode('|', [
            (string)$user->id,
            (string)$setting->id,
            (string)($queueUuid ?? $templateIndex ?? $template),
            (string)$leadId,
            (string)$payloadJson,
        ]));

        $transaction = Transaction::query()->firstOrCreate([
            'user_id' => $user->id,
            'event_key' => $eventKey,
        ], [
            'lead_id' => $leadId,
            'body' => $payloadJson,
            'type' => $settingTemplate['strategy'] ?? null,
            'template' => $templateIndex,
            'queue_uuid' => $queueUuid,
            'distribution_setting_id' => $setting->id,
            'schedule' => ($settingTemplate['schedule'] ?? 'schedule_no') === 'schedule_yes',
            'status' => false,
        ]);

        if ($transaction->wasRecentlyCreated) {
            ResponsibleSend::dispatch($transaction->id, $setting->id, $user->id);
        }

        return response()->json([
            'ok' => true,
            'queued' => $transaction->wasRecentlyCreated,
            'duplicate' => !$transaction->wasRecentlyCreated,
            'transaction_id' => $transaction->id,
        ], $transaction->wasRecentlyCreated ? 201 : 200);
    }

    private function resolveLeadId(array $payload): ?int
    {
        $leadId = $payload['leads']['status'][0]['id']
            ?? $payload['leads']['add'][0]['id']
            ?? $payload['leads']['update'][0]['id']
            ?? $payload['leads']['restore'][0]['id']
            ?? $payload['leads']['responsible'][0]['id']
            ?? null;

        if (is_numeric($leadId)) {
            return (int)$leadId;
        }

        $leadsPayload = $payload['leads'] ?? null;
        if (!is_array($leadsPayload)) {
            return null;
        }

        return $this->findNumericIdRecursively($leadsPayload);
    }

    private function resolvePayload(Request $request): array
    {
        $payload = $request->toArray();
        if (is_array($payload) && !empty($payload)) {
            $normalized = $this->normalizePayload($payload);
            if (!empty($normalized)) {
                return $normalized;
            }

            return $payload;
        }

        $rawBody = trim((string)$request->getContent());
        if ($rawBody === '') {
            return [];
        }

        $decodedJson = json_decode($rawBody, true);
        if (is_array($decodedJson)) {
            return $this->normalizePayload($decodedJson);
        }

        $parsed = [];
        parse_str($rawBody, $parsed);
        if (is_array($parsed) && !empty($parsed)) {
            return $this->normalizePayload($parsed);
        }

        return [];
    }

    private function normalizePayload(array $payload): array
    {
        if (isset($payload['leads']) && is_string($payload['leads'])) {
            $decodedLeads = json_decode($payload['leads'], true);
            if (is_array($decodedLeads)) {
                $payload['leads'] = $decodedLeads;
            } else {
                $parsedLeads = [];
                parse_str($payload['leads'], $parsedLeads);
                if (!empty($parsedLeads)) {
                    $payload['leads'] = $parsedLeads;
                }
            }
        }

        foreach (['payload', 'data', 'request', 'body'] as $wrapperKey) {
            if (!isset($payload[$wrapperKey]) || !is_string($payload[$wrapperKey])) {
                continue;
            }

            $decodedWrapped = json_decode($payload[$wrapperKey], true);
            if (is_array($decodedWrapped)) {
                return $decodedWrapped;
            }

            $parsedWrapped = [];
            parse_str($payload[$wrapperKey], $parsedWrapped);
            if (!empty($parsedWrapped)) {
                return $parsedWrapped;
            }
        }

        return $payload;
    }

    private function findNumericIdRecursively(mixed $node): ?int
    {
        if (!is_array($node)) {
            return null;
        }

        foreach ($node as $key => $value) {
            if (in_array((string)$key, ['id', 'lead_id'], true) && is_numeric($value)) {
                return (int)$value;
            }

            $found = $this->findNumericIdRecursively($value);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function resolveTemplate(array $settings, string $template): array
    {
        if (ctype_digit($template)) {
            $index = (int)$template;
            if (array_key_exists($index, $settings) && is_array($settings[$index])) {
                $queue = $settings[$index];

                return [$queue, $index, $queue['queue_uuid'] ?? null];
            }
        }

        foreach ($settings as $index => $queue) {
            if (!is_array($queue)) {
                continue;
            }

            if (($queue['queue_uuid'] ?? null) === $template) {
                return [$queue, (int)$index, $template];
            }
        }

        $normalizedQueues = [];
        foreach ($settings as $index => $queue) {
            if (!is_array($queue)) {
                continue;
            }

            $normalizedQueues[] = [
                'index' => (int)$index,
                'queue' => $queue,
            ];
        }

        if (count($normalizedQueues) === 1) {
            $single = $normalizedQueues[0];

            return [$single['queue'], $single['index'], $single['queue']['queue_uuid'] ?? null];
        }

        return [null, null, null];
    }
}
