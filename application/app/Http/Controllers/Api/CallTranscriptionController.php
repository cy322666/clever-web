<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Call\CallTranscription;
use App\Models\amoCRM\Field;
use App\Models\Integrations\CallTranscription\Setting;
use App\Models\Integrations\CallTranscription\Transaction;
use App\Models\User;
use App\Services\Ai\YandexGptService;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Notes;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CallTranscriptionController extends Controller
{
    /**
     * @throws \Exception
     */
    public function hook(User $user, Request $request, string $form)
    {
        $dataNote = $request->contacts['note'][0]['note'] ?? $request->leads['note'][0]['note'];

        if ($dataNote['note_type'] != 11 && $dataNote['note_type'] != 10) {
            return;
        }

        $noteText = json_decode($dataNote['text'], true);

        $settingsModel = Setting::query()
            ->where('user_id', $user->id)
            ->first();

        //TODO?
        if (!$settingsModel?->active) {
            return new Response(null, 403);
        }

        $account = $settingsModel->amoAccount(false, 'call-transcription');
        if (!$account) {
            return new Response('amoCRM account not configured', 422);
        }

        $settings = $settingsModel->settings ?? [];
        $settings = is_string($settings) ? json_decode($settings, true) : $settings;
        $settings = is_array($settings) ? $settings : [];

        $settingBody = $settings[$form] ?? [];
        $duration = isset($noteText['DURATION']) && is_numeric($noteText['DURATION'])
            ? (int)$noteText['DURATION']
            : null;
        $recordingUrl = $noteText['LINK'] ?? null;

        if (empty($settingBody) || $settingBody === [] || empty($recordingUrl)) {
            return new Response(null, 404);
        }

        $timeAt = isset($settingBody['time_at']) && is_numeric($settingBody['time_at'])
            ? (int)$settingBody['time_at']
            : null;
        $timeTo = isset($settingBody['time_to']) && is_numeric($settingBody['time_to'])
            ? (int)$settingBody['time_to']
            : null;

        if ($duration !== null) {
            if ($timeAt !== null && $duration < $timeAt) {
                return new Response(null, 204);
            }

            if ($timeTo !== null && $duration > $timeTo) {
                return new Response(null, 204);
            }
        }

        $transaction = Transaction::query()->create([
            'lead_id' => $dataNote['element_type'] == 2 ? $dataNote['element_id'] : null,
            'contact_id' => $dataNote['element_type'] == 1 ? $dataNote['element_id'] : null,
            'duration' => $duration,
            'note_type' => $dataNote['note_type'],
            'setting_id' => $settingsModel->id,
            'form_setting_id' => $form,
            'user_id' => $user->id,
            'account_id' => $account->id,
            'call_status' => $noteText['call_status'] ?? null,
            'url' => $recordingUrl,
        ]);

        CallTranscription::dispatch($transaction, $account, $settingsModel);
    }
}
