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

        $settings = $settingsModel->settings ?? [];

        if (is_string($settings)) {
            $settingBody = json_decode($settings, true)[$form] ?? [];
        }

        if (empty($settingBody) || $settingBody === []) {
            return new Response(null, 404);
        }

        $transaction = Transaction::query()->create([
            'lead_id' => $dataNote['element_type'] == 2 ? $dataNote['element_id'] : null,
            'contact_id' => $dataNote['element_type'] == 1 ? $dataNote['element_id'] : null,
            'duration' => $noteText['DURATION'],
            'note_type' => $dataNote['note_type'],
            'setting_id' => $settingsModel->id,
            'form_setting_id' => $form,
            'user_id' => $user->id,
            'account_id' => $user->account->id,
            'call_status' => $noteText['call_status'],
            'url' => $noteText['LINK'],
        ]);

        CallTranscription::dispatch($transaction, $user->account, $settingsModel);
    }
}
