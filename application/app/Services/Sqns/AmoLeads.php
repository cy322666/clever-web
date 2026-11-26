<?php

namespace App\Services\Sqns;

use App\Models\Integrations\Sqns\Visit;
use App\Services\amoCRM\Client as AmoClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

class AmoLeads
{
    /**
     * Загружает сделку по ID, при ошибке возвращает null.
     */
    public function get(AmoClient $amoApi, int $id): ?Lead
    {
        try {
            $lead = $amoApi->service->leads()->find($id);

            return $lead instanceof Lead ? $lead : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Возвращает открытые сделки контакта в выбранных воронках.
     */
    public function openForContact(Contact $contact, array $pipelineIds): array
    {
        $pipelineIds = array_map('intval', $pipelineIds);

        return collect($contact->leads)
            ->filter(function (mixed $lead) use ($pipelineIds): bool {
                if (! $lead instanceof Lead || in_array((int) $lead->status_id, [142, 143], true)) {
                    return false;
                }

                return $pipelineIds === [] || in_array((int) $lead->pipeline_id, $pipelineIds, true);
            })
            ->values()
            ->all();
    }

    /**
     * Создаёт сделку по визиту на заданном этапе.
     */
    public function create(
        ?Contact $contact,
        AmoClient $amoApi,
        object $status,
        Visit $visit,
        ?int $responsibleUserId,
    ): Lead {
        [$pipelineId, $statusId] = $this->validatedStatus($status);
        $lead = $contact ? $contact->createLead() : $amoApi->service->leads()->create();
        $lead->name = 'Визит SQNS #'.$visit->visit_id;
        $lead->sale = self::normalizePrice($visit->cost);
        $lead->pipeline_id = $pipelineId;
        $lead->status_id = $statusId;

        if ($responsibleUserId) {
            $lead->responsible_user_id = $responsibleUserId;
        }

        $lead->save();

        return $lead;
    }

    /**
     * Обновляет этап, бюджет и ответственного. При конфликте повторяет сохранение.
     * @throws Throwable
     */
    public function update(Lead $lead, object $status, Visit $visit, ?int $responsibleUserId): Lead
    {
        [$pipelineId, $statusId] = $this->validatedStatus($status);
        $current = $lead;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $current->sale = self::normalizePrice($visit->cost);
            $current->pipeline_id = $pipelineId;
            $current->status_id = $statusId;

            if ($responsibleUserId) {
                $current->responsible_user_id = $responsibleUserId;
            }

            try {
                $current->save();

                return $current;
            } catch (Throwable $exception) {
                if (! Str::contains($exception->getMessage(), 'Last modified date is older than in', true) || $attempt === 3) {
                    throw $exception;
                }

                $fresh = $current->id ? $current->service->find($current->id) : null;

                if (! $fresh instanceof Lead) {
                    throw $exception;
                }

                Log::warning('SQNS amoCRM lead update conflict, retrying.', [
                    'lead_id' => $current->id,
                    'visit_id' => $visit->visit_id,
                    'attempt' => $attempt,
                ]);

                usleep(250000 * $attempt);
                $current = $fresh;
            }
        }

        return $current;
    }

    /**
     * Приводит бюджет сделки к целому числу для amoCRM.
     */
    public static function normalizePrice(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));

        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * Проверяет и возвращает ID воронки и этапа.
     */
    private function validatedStatus(object $status): array
    {
        $pipelineId = (int) ($status->pipeline_id ?? 0);
        $statusId = (int) ($status->status_id ?? 0);

        if ($pipelineId <= 0 || $statusId <= 0) {
            throw new InvalidArgumentException('Не настроен этап amoCRM для текущего статуса SQNS.');
        }

        return [$pipelineId, $statusId];
    }
}
