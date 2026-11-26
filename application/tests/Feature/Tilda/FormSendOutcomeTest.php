<?php

namespace Tests\Feature\Tilda;

use App\Filament\Resources\Integrations\Tilda\FormResource;
use App\Jobs\Tilda\FormSend;
use App\Models\Core\Account;
use App\Models\Integrations\Tilda\Form;
use App\Models\Integrations\Tilda\Setting;
use App\Models\User;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class FormSendOutcomeTest extends TestCase
{
    private array $beforeMigration;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite', 'database.connections.sqlite.database'=>':memory:',
            'queue.failed.database'=>'sqlite', 'alerts.cache_store'=>'array']);
        DB::purge('sqlite');
        Http::preventStrayRequests();
        Queue::fake();
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('email'); $table->string('name')->nullable();
            $table->boolean('is_root')->default(false); $table->timestamps();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->string('widget');
            $table->string('subdomain')->nullable(); $table->string('zone')->nullable();
            $table->boolean('active')->default(false); $table->timestamps();
        });
        Schema::create('tilda_settings', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->text('settings')->nullable(); $table->timestamps();
        });
        Schema::create('tilda_forms', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->text('body')->nullable();
            $table->string('site')->nullable(); $table->boolean('status')->nullable();
            $table->integer('lead_id')->nullable(); $table->integer('contact_id')->nullable(); $table->timestamps();
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id(); $table->string('uuid'); $table->string('connection'); $table->string('queue');
            $table->text('payload'); $table->text('exception'); $table->timestamp('failed_at');
        });
        DB::table('users')->insert(['id'=>1,'email'=>'tilda-outcome@example.invalid','name'=>'Tilda outcome','is_root'=>true]);
        DB::table('accounts')->insert(['id'=>1,'user_id'=>1,'widget'=>Account::DEFAULT_WIDGET,'subdomain'=>'synthetic','zone'=>'ru']);
        DB::table('tilda_settings')->insert(['id'=>1,'user_id'=>1,'settings'=>'{}']);
        DB::table('tilda_forms')->insert(['id'=>1,'user_id'=>1,'body'=>'{"name":"Synthetic form"}',
            'site'=>'synthetic-site','status'=>false,'lead_id'=>202,'contact_id'=>101,'created_at'=>now(),'updated_at'=>now()]);
        $this->beforeMigration=(array)DB::table('tilda_forms')->first();
        (require database_path('migrations/2026_11_22_180000_add_delivery_failure_to_tilda_forms.php'))->up();
    }

    public function test_returned_command_error_stays_failed_after_the_queue_finishes_without_resending(): void
    {
        $processed=0;
        $errors=$this->captureQueueFailures();
        Event::listen(JobProcessing::class, function () use (&$processed): void { $processed++; });
        $this->synchronousQueue()->push($this->job(), '', 'tilda_form');
        $form=Form::findOrFail(1);
        $this->assertFalse($form->status);
        $this->assertSame('Ошибка',$form->deliveryStatus());
        $this->assertStringContainsString('missing site settings',$form->last_error);
        $this->assertNotNull($form->failed_at);
        $this->assertSame(202,$form->lead_id);
        $this->assertSame(101,$form->contact_id);
        $this->assertCount(1,$errors);
        $this->assertStringContainsString('missing site settings',$errors[0]);
        $this->assertSame(1,$processed);
        Queue::assertNotPushed(FormSend::class);
        Http::assertNothingSent();
    }

    public function test_thrown_error_is_terminal_and_preserves_crm_references(): void
    {
        $errors=$this->captureQueueFailures();
        Artisan::shouldReceive('call')->once()->with('app:tilda-form-send',['form'=>1,'account'=>1,'setting'=>1])
            ->andThrow(new RuntimeException('CRM temporarily unavailable'));
        $this->synchronousQueue()->push($this->job(), '', 'tilda_form');
        $form=Form::findOrFail(1);
        $this->assertSame('CRM temporarily unavailable',$form->last_error);
        $this->assertSame(202,$form->lead_id);
        $this->assertSame(101,$form->contact_id);
        $this->assertSame(['CRM temporarily unavailable'],$errors->all());
        Queue::assertNotPushed(FormSend::class);
        Http::assertNothingSent();
    }

    public function test_a_successful_send_remains_successful_and_clears_a_previous_error(): void
    {
        $errors=$this->captureQueueFailures();
        Form::findOrFail(1)->markFailed('Previous error');
        Artisan::shouldReceive('call')->once()->andReturnUsing(function (): int {
            Form::findOrFail(1)->markExported(303,404);
            return 0;
        });
        Artisan::shouldNotReceive('output');
        $this->synchronousQueue()->push($this->job(), '', 'tilda_form');
        $form=Form::findOrFail(1);
        $this->assertTrue($form->status);
        $this->assertSame('Выгружен',$form->deliveryStatus());
        $this->assertNull($form->last_error);
        $this->assertNull($form->failed_at);
        $this->assertSame(303,$form->contact_id);
        $this->assertSame(404,$form->lead_id);
        $this->assertCount(0,$errors);
        Queue::assertNotPushed(FormSend::class);
        Http::assertNothingSent();
    }

    public function test_queued_payload_allows_only_one_attempt_and_fails_on_timeout(): void
    {
        $queue=$this->synchronousQueue();
        $payload=json_decode((new \ReflectionMethod($queue,'createPayload'))->invoke($queue,$this->job(),'tilda_form'),true);
        $this->assertSame(1,$payload['maxTries']);
        $this->assertTrue($payload['failOnTimeout']);
        $this->assertNull($payload['retryUntil']);
        Http::assertNothingSent();
    }

    public function test_migration_preserves_existing_forms_and_can_be_repeated(): void
    {
        $this->assertSame($this->beforeMigration,array_intersect_key((array)DB::table('tilda_forms')->first(),$this->beforeMigration));
        (require database_path('migrations/2026_11_22_180000_add_delivery_failure_to_tilda_forms.php'))->up();
        $this->assertTrue(Schema::hasColumns('tilda_forms',['last_error','failed_at']));
        $this->assertSame(1,Form::count());
        $this->assertSame('Ожидает',Form::findOrFail(1)->deliveryStatus());
    }

    public function test_failure_text_is_bounded_and_the_transaction_table_renders_an_error_badge(): void
    {
        $form=Form::findOrFail(1);
        $form->markFailed("\x1B[31m".str_repeat('Ошибка CRM ',300)."\x1B[0m");
        $this->assertSame(2000,mb_strlen($form->last_error));
        $this->assertStringNotContainsString("\x1B",$form->last_error);
        $this->actingAs(User::findOrFail(1));
        $page=Livewire::test(TildaTransactionsFixture::class)->assertSee('Ошибка')->assertDontSee('Выгружен');
        $column=$page->instance()->getTable()->getColumn('status')->record($form);
        $this->assertSame('danger',$column->getColor($column->getState()));
        $this->assertSame($form->last_error,$column->getTooltip());
        Queue::assertNotPushed(FormSend::class);
        Http::assertNothingSent();
    }

    private function captureQueueFailures(): Collection
    {
        $errors=collect();
        Event::listen(JobFailed::class, function (JobFailed $event) use ($errors): void {
            $errors->push($event->exception->getMessage());
        });

        return $errors;
    }

    private function synchronousQueue(): SyncQueue
    {
        $queue=new SyncQueue();
        $queue->setContainer($this->app);
        $queue->setConnectionName('sync');

        return $queue;
    }

    private function job(): FormSend
    {
        return new FormSend(Form::findOrFail(1),Account::findOrFail(1),Setting::findOrFail(1));
    }
}

class TildaTransactionsFixture extends Component implements HasTable, HasSchemas, HasActions
{
    use InteractsWithTable;
    use InteractsWithSchemas;
    use InteractsWithActions;

    public function table(Table $table): Table
    {
        return FormResource::table($table)->query(Form::query());
    }

    public function render(): string
    {
        return '<div>{{ $this->table }}</div>';
    }
}
