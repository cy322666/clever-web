<?php

namespace App\Services\Finder;

use App\Models\Integrations\Finder\Action;
use App\Models\Integrations\Finder\Conversation;
use App\Models\Integrations\Finder\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class Monitor
{
    public function __construct(private WorkingTime $clock, private FinderAccess $access) {}

    public function ingest(Setting $setting, array $payload): int
    {
        $events = [];
        foreach (['message' => 'incoming', 'outgoing_message' => 'outgoing'] as $key => $direction) {
            foreach ((array) data_get($payload, "$key.add", []) as $item) {
                if (! is_array($item) || Validator::make($item, [
                    'id' => 'required|string|max:191', 'chat_id' => 'required|string|max:191',
                    'created_at' => 'required|integer|min:1|max:'.now()->addMinutes(5)->timestamp,
                    'talk_id' => 'nullable|integer|min:1', 'contact_id' => 'nullable|integer|min:1',
                    'element_id' => 'nullable|integer|min:1',
                ])->fails()) {
                    continue;
                }
                $events[] = [...$item, 'direction' => $direction];
            }
        }
        usort($events, fn ($a, $b) => [(int) $a['created_at'], $a['direction']] <=> [(int) $b['created_at'], $b['direction']]);

        return DB::transaction(function () use ($setting, $events): int {
            $setting = Setting::query()->lockForUpdate()->findOrFail($setting->id);
            if (! $this->access->canRun($setting)) {
                return 0;
            }
            $count = 0;
            foreach ($events as $event) {
                $conversation = Conversation::query()->firstOrCreate(['setting_id' => $setting->id, 'chat_id' => $event['chat_id']]);
                $at = CarbonImmutable::createFromTimestamp((int) $event['created_at'], config('app.timezone'));
                $inserted = DB::table('finder_messages')->insertOrIgnore([
                    'conversation_id' => $conversation->id, 'message_id' => $event['id'],
                    'direction' => $event['direction'], 'sent_at' => $at,
                ]);
                if (! $inserted) {
                    continue;
                }
                $count++;
                if (! $conversation->last_message_at || $at->gte($conversation->last_message_at)) {
                    if (! empty($event['talk_id']) && (int) $event['talk_id'] !== (int) $conversation->talk_id) {
                        $conversation->lead_id = null;
                    }
                    foreach (['talk_id', 'contact_id'] as $field) {
                        if (! empty($event[$field])) {
                            $conversation->$field = $event[$field];
                        }
                    }
                    if (in_array((string) ($event['element_type'] ?? ''), ['2', 'lead', 'leads'], true) && ! empty($event['element_id'])) {
                        $conversation->lead_id = $event['element_id'];
                    }
                    $conversation->last_message_at = $at;
                }
                if ($event['direction'] === 'incoming') {
                    if (! $conversation->last_outgoing_at || $at->gt($conversation->last_outgoing_at)) {
                        if (! $conversation->pending_since) {
                            $this->startWaiting($conversation, $setting, $at);
                        } elseif ($at->lt($conversation->pending_since)) {
                            $conversation->pending_since = $at;
                            if ($conversation->attempts === 0) {
                                $conversation->next_check_at = $this->clock->deadline($at, $setting->intervalSeconds(), $setting->options());
                            }
                        }
                    }
                } elseif (! $conversation->last_outgoing_at || $at->gt($conversation->last_outgoing_at)) {
                    $conversation->last_outgoing_at = $at;
                    if ($conversation->pending_since && $at->gte($conversation->pending_since)) {
                        // Cancel queued overdue actions when a reply arrived before delivery.
                        Action::query()->where('conversation_id', $conversation->id)->where('cycle', $conversation->cycle)
                            ->where('event', 'overdue')->where('status', 'pending')->update(['status' => 'cancelled']);
                        $triggered = Action::query()->where('conversation_id', $conversation->id)->where('cycle', $conversation->cycle)
                            ->where('event', 'overdue')->whereIn('status', ['processing', 'succeeded'])->exists();
                        if ($triggered && $setting->options()['run_reply_workflow']) {
                            $this->recordActions($conversation, $setting, 'replied');
                        }
                        $conversation->pending_since = null;
                        $conversation->next_check_at = null;
                        // A late outgoing hook can precede a newer incoming message.
                        $next = DB::table('finder_messages')->where('conversation_id', $conversation->id)
                            ->where('direction', 'incoming')->where('sent_at', '>', $at)->min('sent_at');
                        if ($next) {
                            $this->startWaiting($conversation, $setting, CarbonImmutable::parse($next, config('app.timezone')));
                        }
                    }
                }
                $conversation->save();
            }
            $setting->forceFill(['last_webhook_at' => now()])->save();

            return $count;
        });
    }

    public function check(int $conversationId): bool
    {
        $settingId = Conversation::query()->whereKey($conversationId)->value('setting_id');
        if (! $settingId) {
            return false;
        }

        return DB::transaction(function () use ($settingId, $conversationId): bool {
            $setting = Setting::query()->lockForUpdate()->findOrFail($settingId);
            $conversation = Conversation::query()->lockForUpdate()->find($conversationId);
            if (! $conversation?->pending_since || ! $conversation->next_check_at || $conversation->next_check_at->isFuture()) {
                return false;
            }
            if (! $this->access->canRun($setting)) {
                $conversation->update(['pending_since' => null, 'next_check_at' => null]);

                return false;
            }
            $options = $setting->options();
            $now = CarbonImmutable::now();
            $opening = $this->clock->deadline($now, 0, $options);
            if ($opening->gt($now)) {
                $conversation->update(['next_check_at' => $opening]);

                return false;
            }
            if ($conversation->attempts >= (int) $options['max_attempts']) {
                $conversation->update(['next_check_at' => null]);

                return false;
            }
            $conversation->attempts++;
            $this->recordActions($conversation, $setting, 'overdue');
            // Do not flood the CRM with missed intervals after a scheduler outage.
            $conversation->next_check_at = $conversation->attempts < (int) $options['max_attempts']
                ? $this->clock->deadline($now, $setting->intervalSeconds(), $options) : null;
            $conversation->save();

            return true;
        });
    }

    private function startWaiting(Conversation $conversation, Setting $setting, CarbonImmutable $at): void
    {
        $conversation->cycle++;
        $conversation->attempts = 0;
        $conversation->pending_since = $at;
        $conversation->next_check_at = $this->clock->deadline($at, $setting->intervalSeconds(), $setting->options());
    }

    private function recordActions(Conversation $conversation, Setting $setting, string $event): void
    {
        $options = $setting->options();
        $kinds = [];
        if ($event === 'replied' || $options['run_workflow']) {
            $kinds[] = 'workflow';
        }
        if ($event === 'overdue' && $options['create_task']) {
            $kinds[] = 'task';
        }
        foreach ($kinds as $kind) {
            Action::query()->firstOrCreate([
                'conversation_id' => $conversation->id, 'cycle' => $conversation->cycle,
                'attempt' => $conversation->attempts, 'event' => $event, 'kind' => $kind,
            ], [
                'setting_id' => $setting->id,
                'payload' => [
                    'account_id' => $setting->account_id,
                    'workflow_id' => $options[$event === 'replied' ? 'reply_workflow_id' : 'workflow_id'],
                    'responsible_user_id' => $options['responsible_user_id'],
                    'task_type_id' => $options['task_type_id'], 'task_text' => $options['task_text'],
                    'task_due_minutes' => $options['task_due_minutes'],
                    'chat_id' => $conversation->chat_id, 'talk_id' => $conversation->talk_id,
                    'contact_id' => $conversation->contact_id, 'lead_id' => $conversation->lead_id,
                    'pending_since' => $conversation->pending_since?->toIso8601String(),
                    'replied_at' => $event === 'replied' ? $conversation->last_outgoing_at?->toIso8601String() : null,
                ],
            ]);
        }
    }
}
