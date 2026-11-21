<?php

namespace Tests\Feature\Core;

use App\Models\Integrations\YClients\Record;
use App\Services\Core\IntegrationHistoryPruner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntegrationHistoryPruningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true, 'cache.default' => 'array']);
        DB::purge('sqlite');
        Http::preventStrayRequests();

        foreach (['tilda_forms', 'distribution_transactions', 'yclients_records', 'sqns_visits', 'vetmanager_visits'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                if (in_array($name, ['tilda_forms', 'distribution_transactions'], true)) {
                    $table->boolean('status')->nullable();
                } else {
                    $table->string('status')->nullable();
                }
                $table->text('error_message')->nullable();
                $table->string('lead_fields_replay_status')->nullable();
                $table->text('lead_fields_replay_error')->nullable();
                $table->text('mapped_fields_update_error')->nullable();
                $table->timestamp('datetime')->nullable();
                $table->timestamp('admission_date')->nullable();
                $table->timestamps();
            });
        }
        Schema::create('yclients_record_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('record_id')->constrained('yclients_records')->cascadeOnDelete();
            $table->string('status')->nullable();
        });
    }

    public function test_yclients_keeps_failed_pending_recent_future_and_unresolved_field_updates(): void
    {
        $table = 'yclients_records';
        $eligible = $this->row($table, ['status' => Record::STATUS_SUCCESS]);
        $keep = [
            $this->row($table, ['status' => Record::STATUS_FAILED]),
            $this->row($table, ['status' => Record::STATUS_PENDING]),
            $this->row($table, ['status' => null]),
            $this->row($table, ['status' => Record::STATUS_SUCCESS, 'created_at' => now()]),
            $this->row($table, ['status' => Record::STATUS_SUCCESS, 'updated_at' => now()]),
            $this->row($table, ['status' => Record::STATUS_SUCCESS, 'datetime' => now()->addDay()]),
            $this->row($table, ['status' => Record::STATUS_SUCCESS, 'error_message' => 'unconfirmed create']),
            $this->row($table, ['status' => Record::STATUS_SUCCESS, 'lead_fields_replay_status' => 'failed']),
            $this->row($table, ['status' => Record::STATUS_SUCCESS, 'lead_fields_replay_error' => 'replay failed']),
            $this->row($table, ['status' => Record::STATUS_SUCCESS, 'mapped_fields_update_error' => 'update failed']),
        ];
        $this->assertSame(1, app(IntegrationHistoryPruner::class)->prune('yclients', 14, 1));
        $this->assertDatabaseMissing($table, ['id' => $eligible]);
        $this->assertSame($keep, DB::table($table)->orderBy('id')->pluck('id')->all());
    }

    public function test_yclients_keeps_every_unfinished_outbox_and_cascades_only_completed_history(): void
    {
        $keep = [];
        foreach ([Record::STATUS_PENDING, Record::STATUS_FAILED, null] as $status) {
            $id = $this->row('yclients_records', ['status' => Record::STATUS_SUCCESS]);
            DB::table('yclients_record_events')->insert(['record_id' => $id, 'status' => $status]);
            $keep[] = $id;
        }
        $done = $this->row('yclients_records', ['status' => Record::STATUS_SUCCESS]);
        DB::table('yclients_record_events')->insert(['record_id' => $done, 'status' => Record::STATUS_SUCCESS]);
        $this->assertSame(1, app(IntegrationHistoryPruner::class)->prune('yclients', 14));
        $this->assertSame($keep, DB::table('yclients_records')->orderBy('id')->pluck('id')->all());
        $this->assertSame(3, DB::table('yclients_record_events')->count());
    }

    public function test_tilda_and_distribution_never_delete_unsent_or_recently_changed_rows(): void
    {
        foreach (['tilda' => 'tilda_forms', 'distribution' => 'distribution_transactions'] as $name => $table) {
            $this->row($table, ['status' => true]);
            $pending = $this->row($table, ['status' => false]);
            $unknown = $this->row($table, ['status' => null]);
            $updated = $this->row($table, ['status' => true, 'updated_at' => now()]);
            $this->assertSame(1, app(IntegrationHistoryPruner::class)->prune($name, 14, 1));
            $this->assertSame([$pending, $unknown, $updated], DB::table($table)->orderBy('id')->pluck('id')->all());
        }
    }

    public function test_clinic_history_prunes_successes_globally_without_a_logged_in_user(): void
    {
        Auth::forgetGuards();
        $this->assertFalse(Auth::check());
        foreach (['sqns' => ['sqns_visits', 'datetime'], 'vetmanager' => ['vetmanager_visits', 'admission_date']] as $name => [$table, $dateColumn]) {
            $this->row($table, ['status' => 'success', 'user_id' => 7]);
            $this->row($table, ['status' => 'success', 'user_id' => 9]);
            $pending = $this->row($table, ['status' => 'pending']);
            $processing = $this->row($table, ['status' => 'processing']);
            $failed = $this->row($table, ['status' => 'failed']);
            $future = $this->row($table, ['status' => 'success', $dateColumn => now()->addDay()]);
            $error = $this->row($table, ['status' => 'success', 'error_message' => 'not reconciled']);
            $this->assertSame(2, app(IntegrationHistoryPruner::class)->prune($name, 14, 1));
            $this->assertSame([$pending, $processing, $failed, $future, $error], DB::table($table)->orderBy('id')->pluck('id')->all());
        }
    }

    public function test_nightly_command_supports_dry_run_and_keeps_unsent_rows(): void
    {
        $sent = $this->row('tilda_forms', ['status' => true]);
        $pending = $this->row('tilda_forms', ['status' => false]);
        $this->artisan('app:clear-month-log', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('tilda_forms', ['id' => $sent]);
        $this->artisan('app:clear-month-log', ['--chunk' => 1])->assertSuccessful();
        $this->assertDatabaseMissing('tilda_forms', ['id' => $sent]);
        $this->assertDatabaseHas('tilda_forms', ['id' => $pending]);
    }

    private function row(string $table, array $attributes): int
    {
        return DB::table($table)->insertGetId(array_replace([
            'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30),
        ], $attributes));
    }
}
