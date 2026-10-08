<?php

namespace Tests\Feature\Core;

use App\Jobs\Integrations\SendIntegrationErrorAlert;
use App\Listeners\SendFailedJobAlert;
use App\Models\Core\Account;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Workflows\WorkflowRun;
use App\Services\amoCRM\AmoAuthFailureNotifier;
use App\Services\Core\AlertService;
use App\Services\Integrations\IntegrationErrorNotifier;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class IntegrationErrorNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'incident_test',
            'database.connections.incident_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'alerts.enabled' => true, 'alerts.integration_errors.enabled' => true,
            'alerts.cache_store' => 'array', 'alerts.integration_errors.cooldown_seconds' => 1800,
            'widget_lifecycle.telegram.token' => 'private-bot-token',
            'widget_lifecycle.telegram.chat_id' => 'lifecycle-chat',
            'widget_lifecycle.telegram.message_thread_id' => null,
            'alerts.channels.telegram.token' => 'another-bot',
            'alerts.channels.telegram.chat_id' => 'another-chat',
            'app.url' => 'https://platform.example',
        ]);
        DB::purge('incident_test');
        Cache::store('array')->flush();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget');
            $table->boolean('active')->default(true);
            $table->string('subdomain');
            $table->string('zone')->default('ru');
            $table->string('access_token')->nullable();
            $table->string('refresh_token')->nullable();
        });
        Schema::create('import_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('ulid')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('workflow_id');
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('queue_monitors', function (Blueprint $table): void {
            $table->id();
            $table->string('job_id');
            $table->string('name')->nullable();
            $table->string('queue')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->boolean('failed')->default(false);
            $table->integer('attempt')->default(0);
            $table->integer('progress')->nullable();
            $table->text('exception_message')->nullable();
            $table->string('tenant_id')->nullable();
            $table->timestamps();
        });
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'First client', 'email' => 'first@example.test'],
            ['id' => 2, 'name' => 'Second client', 'email' => 'second@example.test'],
        ]);
        DB::table('accounts')->insert([
            ['id' => 11, 'user_id' => 1, 'widget' => 'import-excel', 'subdomain' => 'first', 'access_token' => 'secret-access', 'refresh_token' => 'secret-refresh'],
            ['id' => 22, 'user_id' => 2, 'widget' => 'import-excel', 'subdomain' => 'second', 'access_token' => 'other-secret-access', 'refresh_token' => 'other-secret-refresh'],
        ]);
    }

    public function test_failed_record_queues_safe_client_specific_message_and_does_not_change_business_data(): void
    {
        $row = ImportRecord::create(['user_id' => 1, 'status' => 'processing']);
        Queue::assertNothingPushed();
        $error = 'HTTP 401 access_token=super-secret password=hidden customer@example.test';
        $row->update(['status' => 'failed', 'error_message' => $error]);
        Queue::assertPushed(SendIntegrationErrorAlert::class, function ($job): bool {
            $this->assertStringContainsString('first@example.test', $job->message);
            $this->assertStringContainsString('Импорт Excel', $job->message);
            $this->assertStringContainsString('first.amocrm.ru', $job->message);
            $this->assertStringContainsString('авторизацию', $job->message);
            foreach (['super-secret', 'hidden', 'customer@example.test', 'secret-access', 'secret-refresh', 'private-bot-token'] as $secret) {
                $this->assertStringNotContainsString($secret, serialize($job));
            }

            return true;
        });
        $this->assertDatabaseHas('import_records', ['id' => $row->id, 'error_message' => $error]);
        $this->assertDatabaseHas('accounts', ['id' => 11, 'active' => true, 'access_token' => 'secret-access']);
    }

    public function test_error_observer_waits_for_commit_and_does_not_alert_on_rollback(): void
    {
        DB::beginTransaction();
        ImportRecord::create(['user_id' => 1, 'status' => 'failed', 'error_message' => 'HTTP 401']);
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        DB::beginTransaction();
        ImportRecord::create(['user_id' => 1, 'status' => 'failed', 'error_message' => 'HTTP 401']);
        Queue::assertNothingPushed();
        DB::commit();
        Queue::assertPushed(SendIntegrationErrorAlert::class, 1);
    }

    public function test_workflow_failure_alerts_even_without_telegram_failure_strategy(): void
    {
        DB::table('workflow_runs')->insert(['id' => 7, 'user_id' => 1, 'workflow_id' => 107, 'status' => 'running']);
        WorkflowRun::findOrFail(7)->markFailed('HTTP 429 private-response');
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Потоки') && str_contains($job->message, 'Сценарий #107, запуск #7')
            && str_contains($job->message, '/panel/workflows/107/edit') && ! str_contains($job->message, 'private-response'));
    }

    public function test_repeated_records_are_grouped_but_other_clients_and_causes_are_not_lost(): void
    {
        $notifier = app(IntegrationErrorNotifier::class);
        for ($i = 0; $i < 20; $i++) {
            $notifier->report('import-excel', 'HTTP 401', 1, 11, 'Запись #'.$i);
        }
        $notifier->report('import-excel', 'HTTP 401', 2, 22);
        $notifier->report('import-excel', 'HTTP 429', 1, 11);
        $notifier->report('tilda', 'HTTP 401', 1, 11);
        Queue::assertPushed(SendIntegrationErrorAlert::class, 4);
    }

    public function test_queue_failure_uses_trusted_tags_without_unserializing_job_data(): void
    {
        $event = $this->failedJob('App\\Jobs\\Tilda\\FormSend', ['widget:tilda', 'widget:default', 'account:11', 'user:1']);
        (new SendFailedJobAlert)->handle($event);
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Тильда') && str_contains($job->message, 'first.amocrm.ru')
            && str_contains($job->message, 'FormSend') && ! str_contains($job->message, 'serialized-secret'));
    }

    public function test_auth_failure_alerts_admin_even_if_client_email_is_missing(): void
    {
        DB::table('users')->where('id', 1)->update(['email' => null]);
        app(AmoAuthFailureNotifier::class)->notify(Account::findOrFail(11), new RuntimeException('invalid_grant secret-value'));
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Клиент: ID 1'));
        Mail::assertNothingQueued();
    }

    public function test_wrong_account_user_pair_does_not_leak_another_client_identity(): void
    {
        app(IntegrationErrorNotifier::class)->report('import-excel', 'HTTP 401', 1, 22);
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'first@example.test') && ! str_contains($job->message, 'second'));
    }

    public function test_http_validation_errors_are_ignored_and_server_errors_do_not_forward_request_secrets(): void
    {
        $request = Request::create('https://platform.example/api/test?token=raw-secret', 'POST', ['password' => 'private-password']);
        $request->setUserResolver(fn () => \App\Models\User::findOrFail(1));
        $notifier = app(IntegrationErrorNotifier::class);
        $notifier->httpFailed(new HttpException(403, 'Forbidden'), $request);
        Queue::assertNothingPushed();
        $notifier->httpFailed(new RuntimeException('SQLSTATE raw-secret private-password'), $request);
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'first@example.test') && ! str_contains($job->message, 'raw-secret')
            && ! str_contains($job->message, 'private-password'));
    }

    public function test_delivery_uses_lifecycle_chat_and_suppresses_duplicates_only_after_success(): void
    {
        config(['widget_lifecycle.telegram.message_thread_id' => 42]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        app(IntegrationErrorNotifier::class)->report('import-excel', 'HTTP 401', 1, 11);
        $job = Queue::pushed(SendIntegrationErrorAlert::class)->first();
        $this->assertStringNotContainsString('Повторы этой проблемы', $job->message);
        $this->assertSame(1800, app(IntegrationErrorNotifier::class)->cooldown());
        $job->handle();
        $job->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['chat_id'] === 'lifecycle-chat'
            && $request['message_thread_id'] === 42 && ! isset($request['parse_mode']));
        $this->travel(6)->minutes();
        app(IntegrationErrorNotifier::class)->report('import-excel', 'HTTP 401', 1, 11);
        Queue::assertPushed(SendIntegrationErrorAlert::class, 1);
        $this->travel(25)->minutes();
        app(IntegrationErrorNotifier::class)->report('import-excel', 'HTTP 401', 1, 11);
        Queue::assertPushed(SendIntegrationErrorAlert::class, 2);
    }

    public function test_telegram_failure_can_retry_and_never_persists_bot_token_in_exception(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false], 500)->push(['ok' => true])]);
        $job = new SendIntegrationErrorAlert('Safe test', 'retry-test');
        try {
            $job->handle();
            $this->fail('Expected retryable delivery failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('private-bot-token', $exception->getMessage());
            $this->assertFalse(Cache::store('array')->has('integration-errors:sent:retry-test'));
        }
        $job->handle();
        Http::assertSentCount(2);
        $this->assertTrue(Cache::store('array')->has('integration-errors:sent:retry-test'));
    }

    public function test_alert_job_failure_does_not_recursively_alert(): void
    {
        (new SendFailedJobAlert)->handle($this->failedJob(SendIntegrationErrorAlert::class, []));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_queue_unavailable_uses_bounded_direct_delivery_without_breaking_original_operation(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
        app(IntegrationErrorNotifier::class)->report('import-excel', 'HTTP 401', 1, 11);
        Http::assertSentCount(1);
    }

    public function test_disabled_alerts_do_not_queue_or_send(): void
    {
        config(['alerts.integration_errors.enabled' => false]);
        app(IntegrationErrorNotifier::class)->report('import-excel', 'HTTP 401', 1, 11);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_platform_jobs_without_widget_are_not_silently_ignored(): void
    {
        Event::dispatch($this->failedJob('App\\Jobs\\Core\\ReconcilePayments', ['user:2']));
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Ошибка платформы') && str_contains($job->message, 'second@example.test')
            && str_contains($job->message, 'ReconcilePayments'));
    }

    public function test_logged_errors_are_captured_without_exposing_context_or_catching_informational_logs(): void
    {
        Log::info('Tilda information', ['user_id' => 1]);
        Log::warning('Tilda retry', ['user_id' => 1]);
        Queue::assertNothingPushed();
        Log::error('Tilda request failed', ['user_id' => 1, 'account_id' => 11,
            'error' => 'HTTP 429 private-body', 'access_token' => 'do-not-forward']);
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Тильда') && str_contains($job->message, 'first@example.test')
            && ! str_contains(serialize($job), 'private-body') && ! str_contains(serialize($job), 'do-not-forward'));
    }

    public function test_queue_error_context_is_reset_between_clients_and_duplicate_exception_logs_are_grouped(): void
    {
        $first = $this->failedJob('App\\Jobs\\Tilda\\FormSend', ['user:1', 'account:11']);
        Event::dispatch(new JobProcessing('redis', $first->job));
        Event::dispatch(new JobExceptionOccurred('redis', $first->job, $first->exception));
        Log::error('Failure', ['exception' => $first->exception]);
        Queue::assertPushed(SendIntegrationErrorAlert::class, 1);
        Event::dispatch(new Looping('redis', 'tilda_form'));
        Log::error('SQLSTATE connection unavailable');
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Ошибка платформы') && ! str_contains($job->message, 'first@example.test'));
        $second = $this->failedJob('App\\Jobs\\Tilda\\FormSend', ['user:2', 'account:22']);
        Event::dispatch(new JobProcessing('redis', $second->job));
        Log::error('Failure', ['exception' => $second->exception]);
        Event::dispatch(new JobProcessed('redis', $second->job));
        Queue::assertPushed(SendIntegrationErrorAlert::class, 3);
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'second@example.test') && ! str_contains($job->message, 'first@example.test'));
    }

    public function test_failed_alert_job_and_its_subsequent_exception_log_do_not_start_an_alert_loop(): void
    {
        $failure = $this->failedJob(SendIntegrationErrorAlert::class, []);
        Event::dispatch(new JobProcessing('redis', $failure->job));
        Event::dispatch(new JobExceptionOccurred('redis', $failure->job, $failure->exception));
        Event::dispatch($failure);
        Log::error('Telegram failed', ['exception' => $failure->exception]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        Event::dispatch(new Looping('redis', 'default'));
    }

    public function test_webhook_route_owner_is_used_instead_of_logged_in_admin_and_query_string_is_not_sent(): void
    {
        $request = Request::create('/api/tilda/first-user-uuid?secret=private-key', 'POST');
        $route = new \Illuminate\Routing\Route(['POST'], 'api/tilda/{user}', fn () => null);
        $route->name('tilda.hook');
        $route->bind($request);
        $route->setParameter('user', \App\Models\User::findOrFail(1));
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => \App\Models\User::findOrFail(2));
        app(IntegrationErrorNotifier::class)->httpFailed(new RuntimeException('HTTP 500'), $request);
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'first@example.test') && str_contains($job->message, 'HTTP: tilda.hook')
            && str_contains($job->message, 'Тильда') && ! str_contains($job->message, 'second@example.test')
            && ! str_contains(serialize($job), 'private-key'));
    }

    public function test_setting_only_finder_job_resolves_its_client_and_selected_connection(): void
    {
        Schema::create('finder_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
        });
        DB::table('finder_settings')->insert(['id' => 4, 'user_id' => 2, 'account_id' => 22]);
        $job = new \App\Jobs\Integrations\SynchronizeFinderWebhooks(4);
        Event::dispatch($this->failedJob($job::class, $job->tags()));
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Контроль ответов') && str_contains($job->message, 'second@example.test')
            && str_contains($job->message, 'second.amocrm.ru'));
    }

    public function test_scheduler_nonzero_exit_and_exceptions_notify_but_success_does_not(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $task = app(Schedule::class)->command('app:monitor --password=private-option');
        $task->exitCode = 0;
        Event::dispatch(new ScheduledTaskFinished($task, 0.1));
        Http::assertNothingSent();
        $task->exitCode = 1;
        Event::dispatch(new ScheduledTaskFinished($task, 0.1));
        Event::dispatch(new ScheduledBackgroundTaskFinished($task));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['chat_id'] === 'lifecycle-chat'
            && str_contains($request['text'], 'Планировщик: app:monitor')
            && ! str_contains($request['text'], 'private-option'));
        $other = app(Schedule::class)->command('app:other');
        Event::dispatch(new ScheduledTaskFailed($other, new RuntimeException('Permission denied private-file')));
        Http::assertSentCount(2);
    }

    public function test_infrastructure_alerts_use_admin_chat_directly_even_when_legacy_dedupe_cache_is_down(): void
    {
        $path = sys_get_temp_dir().'/clever-alert-cache-'.bin2hex(random_bytes(6));
        config(['alerts.cache_store' => 'broken-alert-store', 'cache.stores.broken-alert-store.driver' => 'missing-driver',
            'cache.stores.file.path' => $path, 'cache.stores.file.lock_path' => $path]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        try {
            AlertService::critical('Очередь: недоступна', 'Connection refused');
            AlertService::critical('Очередь: недоступна', 'Connection refused');
            Http::assertSentCount(1);
            Http::assertSent(fn ($request): bool => $request['chat_id'] === 'lifecycle-chat'
                && str_contains($request['text'], 'Ошибка платформы') && str_contains($request['text'], 'Очередь: недоступна'));
            Queue::assertNothingPushed();
        } finally {
            app('files')->deleteDirectory($path);
        }
    }

    public function test_existing_workflow_telegram_strategy_does_not_duplicate_central_error_notification(): void
    {
        DB::table('workflow_runs')->insert(['id' => 7, 'user_id' => 1, 'workflow_id' => 107, 'status' => 'running']);
        WorkflowRun::findOrFail(7)->markFailed('HTTP 401');
        AlertService::critical('Процесс: ошибка выполнения', 'Процесс упал',
            ['workflow_id' => 107, 'run_id' => 7, 'error' => 'HTTP 401']);
        Queue::assertPushed(SendIntegrationErrorAlert::class, 1);
        Http::assertNothingSent();
    }

    public function test_every_widget_reaches_the_same_admin_pipeline(): void
    {
        $widgets = ['YClients' => 'yclients', 'ImportExcel' => 'import-excel', 'Tilda' => 'tilda',
            'Distribution' => 'distribution', 'Sqns' => 'sqns', 'Vetmanager' => 'vetmanager',
            'Finder' => 'finder', 'Workflows' => 'workflows'];
        foreach ($widgets as $namespace => $widget) {
            (new SendFailedJobAlert)->handle($this->failedJob('App\\Jobs\\'.$namespace.'\\ExampleTask', ['account:11', 'user:1']));
            Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Виджет: '.config('widget_lifecycle.labels.'.$widget))
                && str_contains($job->message, 'first@example.test'));
        }
        Queue::assertPushed(SendIntegrationErrorAlert::class, count($widgets));
    }

    public function test_industry_integrations_alert_on_saved_failure_without_failed_queue_job(): void
    {
        foreach ([\App\Models\Integrations\YClients\Record::class => 'yclients',
            \App\Models\Integrations\Sqns\Visit::class => 'sqns',
            \App\Models\Integrations\Vetmanager\Visit::class => 'vetmanager'] as $class => $widget) {
            Schema::create((new $class)->getTable(), function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('account_id');
                $table->string('status');
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
            $model = $class::create(['user_id' => 1, 'account_id' => 11, 'status' => 'pending']);
            $model->update(['status' => 'failed', 'error_message' => 'HTTP 403 private-payload']);
            Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Виджет: '.config('widget_lifecycle.labels.'.$widget))
                && str_contains($job->message, 'first@example.test') && ! str_contains(serialize($job), 'private-payload'));
        }
        Queue::assertPushed(SendIntegrationErrorAlert::class, 3);
    }

    private function failedJob(string $name, array $tags): JobFailed
    {
        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $jobId = (string) \Illuminate\Support\Str::uuid();
        $job->shouldReceive('getJobId')->andReturn($jobId);
        $job->shouldReceive('resolveName')->andReturn($name);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('payload')->andReturn(['uuid' => $jobId, 'displayName' => $name, 'tags' => $tags,
            'data' => ['command' => 'serialized-secret-never-unserialize']]);

        return new JobFailed('redis', $job, new RuntimeException('HTTP 401 secret-value'));
    }
}
