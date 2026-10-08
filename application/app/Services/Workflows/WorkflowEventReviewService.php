<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowEventReview;
use Illuminate\Support\Facades\DB;

final class WorkflowEventReviewService
{
    public function resolve(int $id, int $ownerId, string $choice): void
    {
        abort_unless(in_array($choice, ['check', 'ignore', 'release']), 422);
        DB::transaction(function () use ($id, $ownerId, $choice) {
            $review = WorkflowEventReview::where('user_id', $ownerId)->lockForUpdate()->findOrFail($id);
            if ($review->status !== 'review') {
                return;
            }
            $account = Account::where('user_id', $ownerId)->findOrFail($review->account_id);
            $workflow = Workflow::withoutGlobalScopes()->where('user_id', $ownerId)->findOrFail($review->workflow_id);
            if ($choice === 'ignore') {
                $review->update(['status' => 'ignored', 'reason' => 'Владелец подтвердил: запуск не нужен']);

                return;
            }
            $envelope = $review->envelope;
            $decision = app(WorkflowWriteJournal::class)->decide($account, $envelope['event']);
            if ($decision['decision'] === 'own') {
                $review->update(['status' => 'ignored', 'reason' => $decision['reason']]);

                return;
            }
            if ($choice === 'check') {
                $review->update(['reason' => $decision['decision'] === 'external'
                    ? 'Подтверждено независимое изменение. Можно выполнить один раз.' : $decision['reason']]);

                return;
            }
            // Do not override an ambiguous origin, even through the UI.
            if ($decision['decision'] !== 'external') {
                throw new \InvalidArgumentException('Источник ещё не подтверждён. Запуск заблокирован, чтобы не вызвать другой процесс собственным изменением.');
            }
            if (! $workflow->is_active) {
                throw new \InvalidArgumentException('Сценарий выключен. Событие сохранено.');
            }
            $workflow->definition = $envelope['definition'];
            app(WorkflowAmoCrmWebhookService::class)->startWorkflow($workflow, $account,
                $envelope['event'], $envelope['payload'], [], $review->start_id, true);
            $review->update(['status' => 'released', 'reason' => 'Независимое событие передано в очередь один раз']);
        });
    }
}
