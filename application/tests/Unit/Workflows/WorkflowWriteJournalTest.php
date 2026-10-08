<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowEventReview;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use App\Services\Workflows\WorkflowEventReviewService;
use App\Services\Workflows\WorkflowWriteJournal;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowWriteJournalTest extends TestCase
{
    private Account $account;

    private Workflow $workflow;

    private WorkflowWriteJournal $journal;

    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        (require database_path('migrations/2026_09_30_190000_create_workflow_event_guard_tables.php'))->up();
        config(['filament-workflows.execution.precise_loop_guard' => true]);
        Http::preventStrayRequests();
        Bus::fake();
        $this->account = (new Account)->forceFill(['user_id' => 1, 'widget' => 'workflows', 'active' => true, 'subdomain' => 'journal-test', 'access_token' => 'fake']);
        $this->account->saveQuietly();
        $this->workflow = (new Workflow)->forceFill(['user_id' => 1, 'name' => 'Guard test', 'is_active' => true, 'trigger_type' => 'webhook', 'definition' => [
            'trigger' => ['type' => 'amocrm-update-lead', 'config' => ['source' => 'amocrm', 'event' => 'update_lead', 'entity' => 'lead', 'action' => 'update']],
            'actions' => [['id' => 'delay', 'type' => 'workflow_delay', 'config' => ['seconds' => 1]]],
        ]]);
        $this->workflow->saveQuietly();
        $this->journal = app(WorkflowWriteJournal::class);
    }

    private function context(): \App\Workflows\Context\WorkflowContext
    {
        return (new \App\Workflows\Context\WorkflowContext)->setWorkflowId($this->workflow->id)->setWorkflowRunId(1);
    }

    private function write(string $operation = 'update'): string
    {
        return $this->journal->begin($this->account, $this->context(), ['entity' => 'lead', 'entity_id' => $operation === 'create' ? 0 : 42, 'operation' => $operation],
            ['name' => 'After'], ['name' => 'Before', 'price' => 100]);
    }

    private function event(array $item = [], string $event = 'update_lead'): array
    {
        return ['entity' => 'lead', 'action' => explode('_', $event)[0], 'event' => $event, 'item' => array_replace(['id' => 42, 'name' => 'After', 'price' => 100, 'last_modified' => 1000], $item)];
    }

    public function test_equal_update_in_same_second_is_held_not_silently_discarded_or_executed(): void
    {
        $id = $this->write();
        $this->journal->finish($id, 'confirmed', ['id' => 42, 'updated_at' => 1000]);
        $this->assertSame('review', $this->journal->decide($this->account, $this->event())['decision']);
        $this->assertSame('external', $this->journal->decide($this->account, $this->event(['last_modified' => 1001]))['decision']);
        $this->assertSame('review', $this->journal->decide($this->account, $this->event(['price' => 200]))['decision']);
        $this->assertSame('review', $this->journal->decide($this->account, $this->event(['last_modified' => null]))['decision']);
    }

    public function test_created_record_and_note_ids_are_exact_proof_even_if_revision_changed(): void
    {
        $id = $this->write('create');
        $this->journal->finish($id, 'confirmed', ['_embedded' => ['leads' => [['id' => 42, 'updated_at' => 1000]]]]);
        $this->assertSame('own', $this->journal->decide($this->account, $this->event(['last_modified' => 1001], 'add_lead'))['decision']);
        $this->assertSame('external', $this->journal->decide($this->account, $this->event(['id' => 43], 'add_lead'))['decision']);
        $note = $this->write('notes');
        $this->journal->finish($note, 'confirmed', ['_embedded' => ['notes' => [['id' => 55, 'updated_at' => 1000]]]]);
        $this->assertSame('own', $this->journal->decide($this->account, $this->event(['id' => 55, 'element_id' => 42], 'note_lead'))['decision']);
        $this->assertSame('external', $this->journal->decide($this->account, $this->event(['id' => 56, 'element_id' => 42], 'note_lead'))['decision']);
    }

    public function test_timeout_and_pending_are_held_but_rejected_writes_are_not(): void
    {
        $id = $this->write();
        $this->assertSame('review', $this->journal->decide($this->account, $this->event())['decision']);
        $this->journal->finish($id, 'uncertain');
        $this->assertSame('review', $this->journal->decide($this->account, $this->event())['decision']);
        $this->journal->finish($id, 'failed');
        $this->assertSame('external', $this->journal->decide($this->account, $this->event())['decision']);
    }

    public function test_isolation_and_no_plaintext_values_in_write_evidence(): void
    {
        $id = $this->write();
        $this->assertStringNotContainsString('Before', DB::table('workflow_write_intents')->where('id', $id)->value('evidence'));
        $this->assertStringNotContainsString('After', DB::table('workflow_write_intents')->where('id', $id)->value('evidence'));
        $this->assertSame('external', $this->journal->decide($this->account, $this->event(['id' => 999]))['decision']);
        $other = (clone $this->account)->forceFill(['user_id' => 2]);
        $this->assertSame('external', $this->journal->decide($other, $this->event())['decision']);
    }

    public function test_enum_representation_difference_cannot_permit_a_recursive_run(): void
    {
        $id = $this->journal->begin($this->account, $this->context(), ['entity' => 'lead', 'entity_id' => 42, 'operation' => 'update'],
            ['custom_fields_values' => [['field_id' => 7, 'values' => [['enum_id' => 1]]]]], ['name' => 'After']);
        $this->journal->finish($id, 'confirmed', ['id' => 42, 'updated_at' => 1000]);
        $this->assertSame('review', $this->journal->decide($this->account, $this->event(['custom_fields' => [['id' => 7, 'values' => [['value' => 'VIP']]]]]))['decision']);
    }

    public function test_pending_intent_is_committed_before_http_mutation_and_timeout_preserves_it(): void
    {
        $executor = app(WorkflowAmoCrmActionExecutor::class);
        (new \ReflectionProperty($executor, 'mutationContext'))->setValue($executor, $this->context());
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['id' => 42, 'name' => 'Before']);
            }
            $this->assertSame('pending', DB::table('workflow_write_intents')->sole()->status);
            throw new \Illuminate\Http\Client\ConnectionException('Simulated timeout');
        });
        try {
            (new \ReflectionMethod($executor, 'amoRequest'))->invoke($executor, $this->account, 'PATCH', '/api/v4/leads/42', ['name' => 'After']);
            $this->fail('Expected timeout');
        } catch (\Illuminate\Http\Client\ConnectionException) {
            $this->assertSame('uncertain', DB::table('workflow_write_intents')->sole()->status);
        }
        Http::assertSentCount(1); // Laravel does not record requests that throw in a fake callback.
    }

    public function test_missing_journal_table_prevents_write_before_any_remote_mutation(): void
    {
        Schema::drop('workflow_write_intents');
        Http::fake(['*' => Http::response(['id' => 42])]);
        $executor = app(WorkflowAmoCrmActionExecutor::class);
        (new \ReflectionProperty($executor, 'mutationContext'))->setValue($executor, $this->context());
        try {
            (new \ReflectionMethod($executor, 'amoRequest'))->invoke($executor, $this->account, 'POST', '/api/v4/leads', [['name' => 'After']]);
            $this->fail('Expected database error');
        } catch (\Illuminate\Database\QueryException) {
            Http::assertNothingSent();
        }
    }

    public function test_webhook_holds_ambiguous_event_for_each_scenario_without_launching_another_process(): void
    {
        $second = $this->workflow->replicate();
        $second->saveQuietly();
        $this->write();
        $result = app(WorkflowAmoCrmWebhookService::class)->handleIncomingWebhook($this->account, ['leads' => ['update' => [$this->event()['item']]]]);
        $this->assertSame(0, $result['started']);
        $this->assertSame(2, WorkflowEventReview::where('status', 'review')->count());
        app(WorkflowAmoCrmWebhookService::class)->handleIncomingWebhook($this->account, ['leads' => ['update' => [$this->event()['item']]]]);
        $this->assertSame(2, WorkflowEventReview::where('status', 'review')->count(), 'Duplicate delivery must not create another releasable review');
        $this->assertStringNotContainsString('After', DB::table('workflow_event_reviews')->first()->envelope);
        Bus::assertNotDispatched(\Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob::class);
    }

    private function review(): WorkflowEventReview
    {
        return WorkflowEventReview::create(['user_id' => 1, 'account_id' => $this->account->id, 'workflow_id' => $this->workflow->id,
            'start_id' => 'trigger', 'event' => 'update_lead', 'entity_id' => 42, 'status' => 'review', 'reason' => 'Unknown',
            'envelope' => ['event' => $this->event(), 'payload' => [], 'definition' => $this->workflow->definition]]);
    }

    public function test_review_rejects_other_owner_and_ambiguous_release_and_can_be_ignored(): void
    {
        $this->write();
        $review = $this->review();
        $service = app(WorkflowEventReviewService::class);
        try {
            $service->resolve($review->id, 2, 'ignore');
            $this->fail('Cross-owner access');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        }
        try {
            $service->resolve($review->id, 1, 'release');
            $this->fail('Ambiguous release');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame('review', $review->fresh()->status);
        $service->resolve($review->id, 1, 'ignore');
        $this->assertSame('ignored', $review->fresh()->status);
        Bus::assertNotDispatched(\Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob::class);
    }

    public function test_review_modal_renders_only_for_owner(): void
    {
        $review = $this->review();
        $this->actingAs(User::findOrFail(1));
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $page = \Livewire\Livewire::test(\App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\ListWorkflows::class);
        $page->mountAction('reviewEvent', ['id' => $review->id])->assertActionMounted('reviewEvent');
        $this->assertStringContainsString('Проверить источник снова', $page->getMountedActionModalHtml());
        $this->assertStringContainsString('Unknown', $page->getMountedActionModalHtml());
    }

    public function test_confirmed_external_review_is_released_once_with_saved_definition(): void
    {
        $review = $this->review();
        $webhooks = $this->createMock(WorkflowAmoCrmWebhookService::class);
        $webhooks->expects($this->once())->method('startWorkflow')->with(
            $this->callback(fn ($workflow) => $workflow->definition === $review->envelope['definition']),
            $this->anything(), $this->event(), [], [], 'trigger', true);
        $this->app->instance(WorkflowAmoCrmWebhookService::class, $webhooks);
        $service = app(WorkflowEventReviewService::class);
        $service->resolve($review->id, 1, 'release');
        $service->resolve($review->id, 1, 'release');
        $this->assertSame('released', $review->fresh()->status);
    }

    public function test_old_ambiguous_proof_does_not_expire_into_permission_to_run(): void
    {
        $id = $this->write();
        DB::table('workflow_write_intents')->where('id', $id)->update(['created_at' => now()->subDays(90)]);
        $this->assertSame('review', $this->journal->decide($this->account, $this->event())['decision']);
    }

    public function test_link_guard_uses_separate_revision_ranges_for_both_entities(): void
    {
        $id = $this->journal->begin($this->account, $this->context(), ['entity' => 'lead', 'entity_id' => 42, 'operation' => 'link'],
            [['to_entity_type' => 'contacts', 'to_entity_id' => 77]], ['id' => 42, 'updated_at' => 990], ['id' => 77, 'updated_at' => 995]);
        $this->journal->finish($id, 'confirmed', [], ['id' => 42, 'updated_at' => 1000], ['id' => 77, 'updated_at' => 1002]);
        $this->assertSame('review', $this->journal->decide($this->account, $this->event())['decision']);
        $this->assertSame('external', $this->journal->decide($this->account, $this->event(['last_modified' => 1003]))['decision']);
        $contact = ['entity' => 'contact', 'event' => 'update_contact', 'item' => ['id' => 77, 'last_modified' => 1002]];
        $this->assertSame('review',$this->journal->decide($this->account,$contact)['decision']);
        $contact['item']['last_modified'] = 1003;
        $this->assertSame('external',$this->journal->decide($this->account,$contact)['decision']);
    }
}
