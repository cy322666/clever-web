<?php

namespace Tests\Unit\YClients;

use App\Jobs\Integrations\SendIntegrationErrorAlert;
use App\Jobs\YClients\RecordSend;
use App\Models\Integrations\YClients\Record;
use App\Services\Integrations\IntegrationErrorNotifier;
use App\Services\YClients\TransientFailureAlert;
use Illuminate\Bus\Dispatcher;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class TransientFailureAlertTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousEvents;
    private IntegrationErrorNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousEvents = Model::getEventDispatcher();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('env', 'testing');
        $app->instance('config', new Repository([
            'app' => ['url' => 'https://example.test'],
            'alerts' => ['enabled' => true, 'cache_store' => 'array',
                'integration_errors' => ['enabled' => true]],
            'widget_lifecycle' => ['telegram' => ['token' => 'test-token', 'chat_id' => 'test-chat']],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        ]));
        $app->instance('cache', new CacheManager($app));
        $app->instance('log', new NullLogger());
        $app->instance(DispatcherContract::class, new Dispatcher($app));
        $app->instance(Factory::class, new Factory());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Bus::fake();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-03 10:01:51');

        $db = new Manager($app);
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->bootEloquent();
        Model::unsetEventDispatcher();
        $db->getConnection()->getSchemaBuilder()->create('yclients_records', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->integer('account_id');
            $table->integer('setting_id');
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
        $db->getConnection()->getSchemaBuilder()->create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('widget');
            $table->string('subdomain');
            $table->string('zone');
        });
        $db->getConnection()->getSchemaBuilder()->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });
        $db->getConnection()->table('accounts')->insert(['id' => 124, 'user_id' => 141, 'widget' => 'yclients',
            'subdomain' => 'example', 'zone' => 'ru']);
        $db->getConnection()->table('users')->insert(['id' => 141, 'email' => 'client@example.test']);
        $this->notifier = new IntegrationErrorNotifier();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->previousEvents !== null) {
            Model::setEventDispatcher($this->previousEvents);
        } else {
            Model::unsetEventDispatcher();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_transient_failure_is_delayed_without_raw_error_in_queue(): void
    {
        $record = $this->record();
        $record->update(['error_message' => $record->error_message."\nrequest secret: private-token"]);
        $this->notifier->modelFailed($record);
        $job = $this->alert();

        $this->assertSame(180, $job->delay);
        $this->assertSame(now()->timestamp + 180, $job->notBefore);
        $this->assertSame((int) $record->id, $job->yclientsFailure->recordId);
        $this->assertStringNotContainsString('private-token', serialize($job));
        $this->assertStringNotContainsString('Invalid API response', serialize($job));
        Http::assertNothingSent();
    }

    public function test_recovery_after_five_seconds_cancels_push_and_cooldown(): void
    {
        $record = $this->record();
        $this->notifier->modelFailed($record);
        $job = $this->alert();
        Carbon::setTestNow(now()->addSeconds(5));
        $record->update(['status' => Record::STATUS_SUCCESS, 'error_message' => null]);
        $this->deliver($job);

        Http::assertNothingSent();
        $this->assertFalse($this->notifier->cache()->has($job->pendingKey()));
        $this->assertFalse($this->notifier->cache()->has('integration-errors:sent:'.$job->fingerprint));
    }

    public function test_persistent_failure_sends_once_after_window(): void
    {
        $this->notifier->modelFailed($this->record());
        $job = $this->alert();
        $this->deliver($job);
        $job->handle();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Не удалось связаться с сервисом'));
        $this->assertTrue($this->notifier->cache()->has('integration-errors:sent:'.$job->fingerprint));
    }

    #[DataProvider('changedStates')]
    public function test_changed_state_does_not_send_obsolete_alert(array $changes): void
    {
        $record = $this->record();
        $this->notifier->modelFailed($record);
        $job = $this->alert();
        $record->update($changes);
        $this->deliver($job);
        Http::assertNothingSent();
    }

    public static function changedStates(): array
    {
        return [
            'pending' => [['status' => Record::STATUS_PENDING]],
            'success with stale error' => [['status' => Record::STATUS_SUCCESS]],
            'different error' => [['error_message' => 'Invalid value for custom field']],
            'different user' => [['user_id' => 999]],
            'different account' => [['account_id' => 999]],
            'different setting' => [['setting_id' => 999]],
        ];
    }

    public function test_deleted_record_cancels_alert(): void
    {
        $record = $this->record();
        $this->notifier->modelFailed($record);
        $job = $this->alert();
        $record->delete();
        $this->deliver($job);
        Http::assertNothingSent();
    }

    public function test_model_observer_and_queue_log_share_one_deferred_alert(): void
    {
        $record = $this->record();
        $this->notifier->modelFailed($record);
        $this->assertTrue($this->notifier->queueFailed($this->failureEvent($record)));

        Bus::assertDispatchedTimes(SendIntegrationErrorAlert::class, 1);
        $this->assertSame(180, $this->alert()->delay);
        Http::assertNothingSent();
    }

    public function test_queue_error_for_already_recovered_record_does_not_bypass_guard(): void
    {
        $record = $this->record();
        $record->update(['status' => Record::STATUS_SUCCESS, 'error_message' => null]);
        $this->assertTrue($this->notifier->queueFailed($this->failureEvent($record)));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_recovering_record_does_not_hide_another_persistent_record(): void
    {
        $first = $this->record();
        $second = $this->record();
        $this->notifier->modelFailed($first);
        $this->notifier->modelFailed($second);
        $jobs = Bus::dispatched(SendIntegrationErrorAlert::class);
        $this->assertCount(2, $jobs);
        $this->assertNotSame($jobs[0]->pendingKey(), $jobs[1]->pendingKey());
        $first->update(['status' => Record::STATUS_SUCCESS, 'error_message' => null]);
        $this->deliver($jobs[0]);
        Http::assertNothingSent();
        $this->deliver($jobs[1]);
        Http::assertSentCount(1);
    }

    #[DataProvider('permanentErrors')]
    public function test_permanent_errors_are_not_delayed(string $error): void
    {
        $record = $this->record();
        $record->update(['error_message' => $error]);
        $this->notifier->modelFailed($record);
        $job = $this->alert();
        $this->assertNull($job->yclientsFailure);
        $this->assertNull($job->delay);
        $job->handle();
        Http::assertSentCount(1);
    }

    public static function permanentErrors(): array
    {
        return [
            ['Invalid API response (non JSON), code: 403'],
            ['Invalid API response (non JSON), code: 429'],
            ['Invalid API response (non JSON), code: 200'],
            ['Invalid value for custom field'],
            ['Unexpected return type: bool'],
        ];
    }

    public function test_connection_exception_is_deferred(): void
    {
        $record = $this->record();
        $record->update(['error_message' => 'exception: Illuminate\\Http\\Client\\ConnectionException']);
        $this->notifier->modelFailed($record);
        $this->assertSame(180, $this->alert()->delay);
    }

    public function test_direct_fallback_cannot_send_before_window(): void
    {
        $this->notifier->modelFailed($this->record());
        $this->alert()->handle();
        Http::assertNothingSent();
        Bus::assertDispatchedTimes(SendIntegrationErrorAlert::class, 2);
    }

    public function test_early_worker_delivery_is_released(): void
    {
        $this->notifier->modelFailed($this->record());
        $job = $this->alert();
        $queueJob = $this->createMock(Job::class);
        $queueJob->expects($this->once())->method('release')->with(180);
        $job->setJob($queueJob);
        $job->handle();
        Http::assertNothingSent();
    }

    public function test_legacy_alert_jobs_and_other_widgets_are_unchanged(): void
    {
        $this->notifier->report('sqns', 'Invalid API response (non JSON), code: 0', 141, 124);
        $job = $this->alert();
        $this->assertNull($job->yclientsFailure);
        unset($job->yclientsFailure, $job->notBefore);
        $restored = unserialize(serialize($job));
        $this->assertNull($restored->yclientsFailure);
        $this->assertSame(0, $restored->notBefore);
        $restored->handle();
        Http::assertSentCount(1);
    }

    private function record(): Record
    {
        return Record::create(['user_id' => 141, 'account_id' => 124, 'setting_id' => 14,
            'status' => Record::STATUS_FAILED,
            'error_message' => "Unhandled exception in YClients RecordSend job.\nerror: Invalid API response (non JSON), code: 0\nexception: Exception"]);
    }

    private function failureEvent(Record $record): JobFailed
    {
        $job = $this->createMock(Job::class);
        $job->method('payload')->willReturn(['displayName' => RecordSend::class,
            'tags' => ['yclients_record:'.$record->id, 'user:141', 'account:124']]);

        return new JobFailed('redis', $job, new RuntimeException('Invalid API response (non JSON), code: 0'));
    }

    private function alert(): SendIntegrationErrorAlert
    {
        Bus::assertDispatchedTimes(SendIntegrationErrorAlert::class, 1);

        return Bus::dispatched(SendIntegrationErrorAlert::class)->first();
    }

    private function deliver(SendIntegrationErrorAlert $job): void
    {
        Carbon::setTestNow(Carbon::createFromTimestamp($job->notBefore));
        $job->handle();
    }
}
