<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Distribution\ResponsibleSend;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\User;
use Illuminate\Http\Request;

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
            return response()->json([
                'ok' => false,
                'message' => 'Queue template not found',
            ], 422);
        }

        $leadId = $this->resolveLeadId($request->toArray());
        if ($leadId === null) {
            return response()->json([
                'ok' => false,
                'message' => 'Lead id is required',
            ], 422);
        }

        $payload = $request->toArray();
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
            ?? null;

        if (!is_numeric($leadId)) {
            return null;
        }

        return (int)$leadId;
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

        return [null, null, null];
    }
}
