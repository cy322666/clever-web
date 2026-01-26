<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\amoCRM\Field;
use App\Models\User;
use App\Services\Ai\YandexGptService;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Notes;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CallTranscriptionController extends Controller
{
    public function hook(User $user, string $setting, Request $request, YandexGptService $ai): Response
    {
        $data = $request->validate([
            'entity_id' => ['required', 'integer'],
            'entity_type' => ['nullable', 'in:leads,contacts'],
            'transcript' => ['required', 'string'],
            'call_id' => ['nullable', 'string'],
        ]);

        $settingsModel = $user->callTranscriptionSetting;

        if (!$settingsModel?->active) {
            return new Response(null, 403);
        }

        $settings = $settingsModel->settings ?? [];

        if (is_string($settings)) {
            $settings = json_decode($settings, true) ?? [];
        }

        $selectedSetting = is_numeric($setting) ? ($settings[(int) $setting] ?? null) : null;

        if (!$selectedSetting) {
            foreach ($settings as $item) {
                if (($item['code'] ?? null) === $setting) {
                    $selectedSetting = $item;
                    break;
                }
            }
        }

        if (!$selectedSetting || ($selectedSetting['enabled'] ?? true) === false) {
            return new Response(null, 404);
        }

        $entityType = $data['entity_type'] ?? $selectedSetting['entity_type'] ?? 'leads';
        $prompt = trim($selectedSetting['prompt'] ?? '');
        $provider = $selectedSetting['ai_provider'] ?? 'yandex';

        if ($provider !== 'yandex') {
            return new Response('Only Yandex GPT is supported right now', 422);
        }

        $result = $prompt ? $ai->generate($prompt, $data['transcript']) : $data['transcript'];

        $amoApi = new Client($user->account);

        $entity = $entityType === 'contacts'
            ? $amoApi->service->contacts()->find($data['entity_id'])
            : $amoApi->service->leads()->find($data['entity_id']);

        if (!$entity) {
            return new Response(null, 404);
        }

        if (($selectedSetting['result_destination'] ?? 'field') === 'note') {
            $notePrefix = trim($selectedSetting['note_prefix'] ?? '');
            $noteText = $notePrefix ? $notePrefix."\n".$result : $result;

            Notes::addOne($entity, $noteText);
        } else {
            $field = Field::query()->find($selectedSetting['field_id'] ?? null);

            if ($field) {
                $entity->cf($field->name)->setValue($result);
                $entity->save();
            }
        }

        if (!empty($selectedSetting['salesbot_id']) && $entityType === 'leads') {
            $amoApi->service->ajax()->post('/api/v4/leads/'.$data['entity_id'].'/actions/salesbot', [
                'bot_id' => (int) $selectedSetting['salesbot_id'],
            ]);
        }

        return new Response(json_encode([
            'status' => 'ok',
            'entity_id' => $data['entity_id'],
        ]), 200, ['Content-Type' => 'application/json']);
    }
}
