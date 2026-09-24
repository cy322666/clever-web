<?php

namespace Tests\Feature\Finder;

use App\Models\Integrations\Finder\Action;
use App\Models\Integrations\Finder\Conversation;
use App\Models\Integrations\Finder\Setting;
use App\Services\Finder\ActionExecutor;
use App\Services\Finder\FinderAccess;
use App\Services\Finder\Monitor;
use App\Services\Finder\WebhookConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FinderDatabase;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    private Setting $setting;

    private Monitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'Europe/Moscow'));
        Http::preventStrayRequests();
        $this->setting = FinderDatabase::prepare();
        $this->monitor = app(Monitor::class);
    }

    private function incoming(string $id = 'm1', string $at = '12:00', string $chat = 'chat-1'): array
    {
        return ['message' => ['add' => [['id' => $id, 'chat_id' => $chat, 'talk_id' => 117, 'contact_id' => 42, 'element_id' => 55, 'element_type' => 2,
            'created_at' => CarbonImmutable::parse('2026-09-24 '.$at, 'Europe/Moscow')->timestamp]]]];
    }

    private function outgoing(string $id = 'reply', string $at = '12:06'): array
    {
        return ['outgoing_message' => ['add' => $this->incoming($id, $at)['message']['add']]];
    }

    private function overdue(): Conversation
    {
        $this->monitor->ingest($this->setting, $this->incoming());
        $this->travel(5)->minutes();
        $conversation = Conversation::firstOrFail();
        $this->assertTrue($this->monitor->check($conversation->id));

        return $conversation->refresh();
    }

    public function test_duplicate_and_subsequent_incoming_do_not_reset_timer(): void
    {
        $this->assertSame(1, $this->monitor->ingest($this->setting, $this->incoming()));
        $this->assertSame(0, $this->monitor->ingest($this->setting, $this->incoming()));
        $this->travel(3)->minutes();
        $this->monitor->ingest($this->setting, $this->incoming('m2', '12:03'));
        $conversation = Conversation::firstOrFail();
        $this->assertSame('12:05', $conversation->next_check_at->format('H:i'));
        $this->assertFalse($this->monitor->check($conversation->id));
        $this->travel(2)->minutes();
        $this->assertTrue($this->monitor->check($conversation->id));
        $this->assertFalse($this->monitor->check($conversation->id));
        $this->assertSame(1, Action::count());
    }

    public function test_legacy_short_interval_waits_at_least_three_minutes_before_each_attempt(): void
    {
        $this->setting->update(['settings' => [...$this->setting->options(), 'minutes' => 1]]);
        $this->monitor->ingest($this->setting, $this->incoming());
        $conversation = Conversation::firstOrFail();
        $this->assertSame('12:03', $conversation->next_check_at->format('H:i'));
        $this->travel(2)->minutes();
        $this->assertFalse($this->monitor->check($conversation->id));
        $this->assertSame(0, Action::count());
        $this->travel(1)->minutes();
        $this->assertTrue($this->monitor->check($conversation->id));
        $this->assertSame('12:06', $conversation->refresh()->next_check_at->format('H:i'));
    }

    public function test_repeat_limit_and_new_cycle_after_reply(): void
    {
        $conversation = $this->overdue();
        for ($i = 0; $i < 3; $i++) {
            $this->travel(5)->minutes();
            $this->monitor->check($conversation->id);
        }
        $this->assertSame(3, Action::count());
        $this->assertNull($conversation->refresh()->next_check_at);
        $this->monitor->ingest($this->setting, $this->outgoing('reply', '12:20'));
        $this->monitor->ingest($this->setting, $this->incoming('new', '12:21'));
        $this->assertSame(0, $conversation->refresh()->attempts);
        $this->assertSame(2, $conversation->cycle);
        $this->assertSame('12:26', $conversation->next_check_at->format('H:i'));
    }

    public function test_reply_cancels_undelivered_actions_without_launching_recovery(): void
    {
        $this->setting->update(['settings' => [...$this->setting->options(), 'run_reply_workflow' => true, 'reply_workflow_id' => 7]]);
        $conversation = $this->overdue();
        $this->travel(1)->minutes();
        $this->monitor->ingest($this->setting, $this->outgoing());
        $this->assertNull($conversation->refresh()->pending_since);
        $this->assertSame('cancelled', Action::first()->status);
        $this->assertSame(0, Action::where('event', 'replied')->count());
    }

    public function test_reply_after_delivered_action_launches_recovery_once(): void
    {
        $this->setting->update(['settings' => [...$this->setting->options(), 'run_reply_workflow' => true, 'reply_workflow_id' => 7]]);
        $this->overdue();
        Action::query()->update(['status' => 'succeeded']);
        $this->travel(1)->minutes();
        $this->monitor->ingest($this->setting, $this->outgoing());
        $this->monitor->ingest($this->setting, $this->outgoing());
        $this->monitor->ingest($this->setting, $this->outgoing('another-reply', '12:07'));
        $action = Action::where('event', 'replied')->sole();
        $this->assertSame(7, $action->payload['workflow_id']);
        $this->assertSame(1, $action->attempt);
    }

    public function test_out_of_order_messages_keep_new_unanswered_cycle_and_ignore_old_input(): void
    {
        $this->travel(4)->minutes();
        $this->monitor->ingest($this->setting, $this->incoming());
        $this->monitor->ingest($this->setting, $this->incoming('m2', '12:04'));
        $this->monitor->ingest($this->setting, $this->outgoing('reply', '12:02'));
        $this->monitor->ingest($this->setting, $this->incoming('stale', '12:01'));
        $conversation = Conversation::sole();
        $this->assertSame('12:04', $conversation->pending_since->format('H:i'));
        $this->assertSame('12:09', $conversation->next_check_at->format('H:i'));
        $this->assertSame(2, $conversation->cycle);
    }

    public function test_chats_and_tenants_are_isolated(): void
    {
        $other = Setting::create(['user_id' => 2, 'account_id' => 1, 'active' => true, 'enabled' => true]);
        $this->assertSame(0, $this->monitor->ingest($other, $this->incoming()));
        $this->monitor->ingest($this->setting, $this->incoming());
        $this->monitor->ingest($this->setting, $this->incoming('m2', '12:00', 'chat-2'));
        $this->monitor->ingest($this->setting, $this->outgoing('reply', '12:01'));
        $this->assertNotNull(Conversation::where('chat_id', 'chat-2')->sole()->pending_since);
        $this->assertSame(2, Conversation::count());
    }

    public function test_disabled_or_expired_access_stops_processing(): void
    {
        $conversation = $this->overdue();
        DB::table('apps')->where('name', 'finder')->update(['status' => 2]);
        $this->assertSame(0, $this->monitor->ingest($this->setting, $this->incoming('disabled')));
        $executor = new class(app(FinderAccess::class)) extends ActionExecutor
        {
            protected function perform(Action $action): int
            {
                throw new \LogicException('Must not execute');
            }
        };
        $executor->deliver(Action::sole()->id);
        $this->assertSame('cancelled', Action::sole()->status);
        $this->travel(5)->minutes();
        $this->assertFalse($this->monitor->check($conversation->id));
        $this->assertNull($conversation->refresh()->pending_since);
    }

    public function test_delivery_is_claimed_once_and_failed_writes_are_not_retried(): void
    {
        $this->overdue();
        $executor = new class(app(FinderAccess::class)) extends ActionExecutor
        {
            public int $calls = 0;

            protected function perform(Action $action): int
            {
                $this->calls++;
                throw new \RuntimeException('timeout with sensitive body');
            }
        };
        $id = Action::sole()->id;
        $executor->deliver($id);
        $executor->deliver($id);
        $this->assertSame(1, $executor->calls);
        $this->assertSame('failed', Action::sole()->status);
        $this->assertStringNotContainsString('sensitive', Action::sole()->error);
    }

    public function test_scheduler_does_not_catch_up_all_missed_intervals_or_run_outside_schedule(): void
    {
        $this->setting->update(['settings' => [...$this->setting->options(), 'working_time' => true, 'schedule' => [['days' => [4], 'from' => '09:00', 'to' => '18:00']]]]);
        $this->monitor->ingest($this->setting, $this->incoming());
        $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/Moscow'));
        $conversation = Conversation::sole();
        $this->assertFalse($this->monitor->check($conversation->id));
        $this->assertSame(0, Action::count());
        $this->assertSame('2026-10-01 09:00', $conversation->refresh()->next_check_at->format('Y-m-d H:i'));
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Europe/Moscow'));
        $this->assertTrue($this->monitor->check($conversation->id));
        $this->assertSame(1, Action::count());
        $this->assertSame('10:05', $conversation->refresh()->next_check_at->format('H:i'));
    }

    public function test_webhook_requires_signature_and_matching_account(): void
    {
        $signature = app(WebhookConnection::class)->signature($this->setting);
        $this->postJson('/api/finder/hook/'.$this->setting->id.'/invalid', $this->incoming())->assertForbidden();
        $this->postJson('/api/finder/hook/'.$this->setting->id.'/'.$signature, [...$this->incoming(), 'account' => ['id' => 999]])->assertForbidden();
        $this->postJson('/api/finder/hook/'.$this->setting->id.'/'.$signature, $this->incoming())->assertOk()->assertJson(['accepted' => 1]);
        $this->assertSame(1, Conversation::count());
    }

    public function test_late_old_hook_cannot_overwrite_current_talk(): void
    {
        $this->travel(4)->minutes();
        $current = $this->incoming('current', '12:04');
        $current['message']['add'][0]['talk_id'] = 999;
        $this->monitor->ingest($this->setting, $current);
        $this->monitor->ingest($this->setting, $this->outgoing('old', '12:01'));
        $this->assertSame(999, Conversation::sole()->talk_id);
        $this->assertSame('12:04', Conversation::sole()->pending_since->format('H:i'));
    }
}
