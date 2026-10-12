<?php

namespace Tests\Unit\YClients;

use App\Jobs\YClients\RecordSend;
use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use Exception;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecordSendTransportRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::dropIfExists('yclients_records');
        Schema::create('yclients_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function test_existing_lead_transport_failure_is_released_without_remaining_failed(): void
    {
        $record = $this->record(42);
        Artisan::shouldReceive('call')
            ->once()
            ->andThrow(new Exception('Invalid API response (non JSON), code: 502'));
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->method('attempts')->willReturn(1);
        $queueJob->expects($this->once())->method('release')->with(30);

        $job = $this->job($record);
        $job->setJob($queueJob);
        $job->handle();

        $record->refresh();
        $this->assertSame(Record::STATUS_PENDING, $record->status);
        $this->assertNull($record->error_message);
    }

    public function test_final_transport_attempt_remains_failed(): void
    {
        $record = $this->record(42);
        Artisan::shouldReceive('call')
            ->once()
            ->andThrow(new Exception('Invalid API response (non JSON), code: 504'));
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->method('attempts')->willReturn(4);
        $queueJob->expects($this->never())->method('release');

        $job = $this->job($record);
        $job->setJob($queueJob);
        $job->handle();

        $record->refresh();
        $this->assertSame(Record::STATUS_FAILED, $record->status);
        $this->assertStringContainsString('code: 504', $record->error_message);
    }

    public function test_unknown_lead_creation_is_not_released_for_automatic_retry(): void
    {
        $record = $this->record(null);
        Artisan::shouldReceive('call')
            ->once()
            ->andThrow(new Exception('Invalid API response (non JSON), code: 502'));
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->method('attempts')->willReturn(1);
        $queueJob->expects($this->never())->method('release');

        $job = $this->job($record);
        $job->setJob($queueJob);
        $job->handle();

        $this->assertSame(Record::STATUS_FAILED, $record->fresh()->status);
    }

    public function test_retry_skips_note_creation(): void
    {
        $record = $this->record(42);
        Artisan::shouldReceive('call')
            ->once()
            ->with('yc:send-record', [
                'record_id' => $record->id,
                'account_id' => 124,
                'setting_id' => 14,
                '--skip-note' => true,
            ])
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->with('yc:update-entities', [
                'record_id' => $record->id,
                'account_id' => 124,
                'setting_id' => 14,
            ])
            ->andReturn(0);
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->method('attempts')->willReturn(2);

        $job = $this->job($record);
        $job->setJob($queueJob);
        $job->handle();
    }

    private function record(?int $leadId): Record
    {
        return Record::query()->create([
            'user_id' => 141,
            'account_id' => 124,
            'setting_id' => 14,
            'record_id' => 123456,
            'lead_id' => $leadId,
            'status' => Record::STATUS_PENDING,
        ]);
    }

    private function job(Record $record): RecordSend
    {
        $account = (new Account())->forceFill(['id' => 124]);
        $setting = (new Setting())->forceFill(['id' => 14]);

        return new RecordSend($record, $account, $setting, true);
    }
}
