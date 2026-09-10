<?php

namespace App\Services\Sqns;

use App\Models\Integrations\Sqns\Visit;
use App\Services\amoCRM\Client as AmoClient;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

class AmoSync
{
    public function __construct(
        private readonly AmoContacts $contacts,
        private readonly AmoLeads $leads,
    ) {}

    public function sync(Visit $visit, bool $createNote): void
    {
        $setting = $visit->setting;
        $account = $visit->account;

        if (! $setting || ! $account) {
            throw new \RuntimeException('Не найдены настройки SQNS или аккаунт amoCRM.');
        }

        $status = $visit->amoStatus($setting);

        if (empty($status->pipeline_id) || empty($status->status_id)) {
            throw new \RuntimeException('Не настроен этап amoCRM для события «'.$visit->eventLabel().'».');
        }

        $pipelines = array_values(array_filter(array_map('intval', (array) $setting->pipelines)));

        if ($pipelines === []) {
            $pipelines = [(int) $status->pipeline_id];
        }

        if (! in_array((int) $status->pipeline_id, $pipelines, true)) {
            throw new \RuntimeException('Выбранный этап события находится вне воронок SQNS.');
        }

        $amoApi = new AmoClient($account);
        $responsibleUserId = $setting->responsibleUserId();
        $client = $visit->scopedClient();
        $contact = $client
            ? ($visit->deleted
                ? $this->contacts->findExisting($client, $amoApi)
                : $this->contacts->resolve($client, $amoApi, $responsibleUserId))
            : null;
        $lead = $this->resolveLead($visit, $contact, $amoApi, $pipelines);

        if (! $lead && $visit->deleted) {
            $visit->forceFill([
                'status' => Visit::STATUS_SUCCESS,
                'error_message' => null,
            ])->save();
            $setting->forceFill(['last_error' => null])->save();

            return;
        }

        $lead = $lead
            ? $this->leads->update($lead, $status, $visit, $responsibleUserId)
            : $this->leads->create($contact, $amoApi, $status, $visit, $responsibleUserId);

        $values = AmoFieldMapper::values($visit, $client);
        $mapper = new AmoFieldMapper($amoApi, $setting);

        if ($contact) {
            $mapper->apply('contacts', (int) $contact->id, $setting->fields_contact, $values);
        }

        $mapper->apply('leads', (int) $lead->id, $setting->fields_lead, $values);

        if ($createNote) {
            $this->createNote($amoApi, $lead, $visit, $client?->name);
        }

        $visit->forceFill([
            'lead_id' => $lead->id,
            'status' => Visit::STATUS_SUCCESS,
            'error_message' => null,
        ])->save();

        $setting->forceFill(['last_error' => null])->save();
    }

    private function resolveLead(Visit $visit, ?Contact $contact, AmoClient $amoApi, array $pipelines): ?Lead
    {
        if ($visit->lead_id) {
            if ($visit->isLeadOwnedByAnotherVisit()) {
                Log::warning('SQNS visit has a lead owned by another visit; stale link ignored.', [
                    'visit_id' => $visit->visit_id,
                    'lead_id' => $visit->lead_id,
                ]);
                $visit->lead_id = null;
            } else {
                $lead = $this->leads->get($amoApi, (int) $visit->lead_id);

                if ($lead) {
                    return $lead;
                }
            }
        }

        if ($visit->deleted) {
            return null;
        }

        if (! $contact) {
            return null;
        }

        foreach ($this->leads->openForContact($contact, $pipelines) as $candidate) {
            $used = Visit::query()
                ->where('account_id', $visit->account_id)
                ->where('lead_id', $candidate->id)
                ->where('visit_id', '!=', $visit->visit_id)
                ->exists();

            if (! $used) {
                return $this->leads->get($amoApi, (int) $candidate->id) ?: $candidate;
            }
        }

        return null;
    }

    private function createNote(AmoClient $amoApi, Lead $lead, Visit $visit, ?string $clientName): void
    {
        $note = $amoApi->service->notes()->create();
        $note->element_id = $lead->id;
        $note->element_type = 2;
        $note->note_type = 4;
        $note->text = implode("\n", array_filter([
            'SQNS, визит #'.$visit->visit_id,
            'Событие: '.$visit->eventLabel(),
            $visit->datetime ? 'Дата и время: '.$visit->datetime->format('d.m.Y H:i') : null,
            $clientName ? 'Клиент: '.$clientName : null,
            $visit->services ? 'Услуги: '.$visit->services : null,
            $visit->cost !== null ? 'Стоимость: '.$visit->cost : null,
            $visit->author ? 'Автор: '.$visit->author : null,
            $visit->organization_name ? 'Организация: '.$visit->organization_name : null,
            $visit->comment ? 'Комментарий: '.$visit->comment : null,
        ]));
        $note->save();
    }
}
