<?php

namespace Tests\Feature\Finder;

use App\Filament\Resources\Integrations\Finder\Pages\EditFinder;
use App\Filament\Resources\Integrations\Finder\Pages\FinderHistory;
use App\Models\App;
use App\Models\Integrations\Finder\Action;
use App\Models\Integrations\Finder\Conversation;
use App\Models\Integrations\Finder\Setting;
use App\Models\User;
use App\Services\Finder\FinderAccess;
use App\Services\Finder\SettingsValidator;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\FinderDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->setting = FinderDatabase::prepare();
        $this->actingAs(User::findOrFail(1));
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_form_renders_saves_and_history_renders(): void
    {
        Livewire::test(EditFinder::class, ['record' => $this->setting->id])
            ->assertSee('finder-test.amocrm.ru')
            ->assertDontSee('Отслеживать ответы на диалоги')
            ->set('data.settings.minutes', 7)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(7, $this->setting->refresh()->settings['minutes']);
        Livewire::test(FinderHistory::class)->assertSuccessful();
        $this->assertArrayHasKey('finder', \App\Models\App::definitions()->all());
    }

    public function test_form_rejects_zero_interval_and_no_action(): void
    {
        Livewire::test(EditFinder::class, ['record' => $this->setting->id])
            ->set('data.settings.minutes', 0)->call('save')->assertHasErrors(['data.settings.minutes']);
        Livewire::test(EditFinder::class, ['record' => $this->setting->id])
            ->set('data.settings.create_task', false)->call('save')->assertHasErrors(['data.settings.run_workflow']);
        $this->assertSame(5, $this->setting->refresh()->settings['minutes']);
    }

    public function test_another_tenant_cannot_open_settings(): void
    {
        $this->actingAs(User::findOrFail(2));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(EditFinder::class, ['record' => $this->setting->id]);
    }

    public function test_foreign_or_disabled_workflow_is_rejected_server_side(): void
    {
        DB::table('workflows')->insert(['id' => 7, 'user_id' => 2, 'name' => 'Other', 'is_active' => true, 'trigger_type' => 'manual']);
        $this->expectException(ValidationException::class);
        app(SettingsValidator::class)->validate([...$this->setting->options(), 'run_workflow' => true, 'workflow_id' => 7], 1, true);
    }

    public function test_empty_schedule_is_rejected_when_working_time_is_enabled(): void
    {
        $this->expectException(ValidationException::class);
        app(SettingsValidator::class)->validate([...$this->setting->options(), 'working_time' => true, 'schedule' => []], 1, true);
    }

    public function test_schedule_round_trip_keeps_selected_days_and_times(): void
    {
        $page = Livewire::test(EditFinder::class, ['record' => $this->setting->id])
            ->set('data.settings.working_time', true);
        $row = array_key_first($page->get('data.settings.schedule'));
        $page->set("data.settings.schedule.$row.days", [1, 3, 5])
            ->set("data.settings.schedule.$row.from", '22:00')
            ->set("data.settings.schedule.$row.to", '06:00')
            ->call('save')->assertHasNoErrors();
        $saved = array_values($this->setting->refresh()->settings['schedule'])[0];
        $this->assertSame([1, 3, 5], array_map('intval', $saved['days']));
        $this->assertSame('22:00', $saved['from']);
        $this->assertSame('06:00', $saved['to']);
    }

    public function test_single_button_stops_pending_checks_and_resumes_monitoring(): void
    {
        $conversation = Conversation::create(['setting_id' => $this->setting->id, 'chat_id' => 'stop-test', 'pending_since' => now(), 'next_check_at' => now()->addMinute()]);
        $action = Action::create(['setting_id' => $this->setting->id, 'conversation_id' => $conversation->id, 'cycle' => 1, 'attempt' => 1, 'event' => 'overdue', 'kind' => 'task', 'payload' => []]);
        $page = Livewire::test(EditFinder::class, ['record' => $this->setting->id]);
        $page->set('data.settings.minutes', 0)->callAction('active')->assertHasNoErrors();
        $this->assertFalse($this->setting->refresh()->isMonitoringEnabled());
        $this->assertFalse($this->setting->active);
        $this->assertFalse($this->setting->enabled);
        $this->assertNull($conversation->refresh()->next_check_at);
        $this->assertSame('cancelled', $action->refresh()->status);

        $page->set('data.settings.minutes', 7)->callAction('active')->assertHasNoErrors();
        $this->assertTrue(app(FinderAccess::class)->canRun($this->setting->refresh()));
        $this->assertSame(7, $this->setting->settings['minutes']);
        $this->assertSame('cancelled', $action->refresh()->status);
    }

    public function test_first_enable_validates_settings_before_starting_trial(): void
    {
        $this->setting->update(['active' => false, 'enabled' => false]);
        $this->setting->app->update(['status' => App::STATE_CREATED]);
        $page = Livewire::test(EditFinder::class, ['record' => $this->setting->id]);
        $page->set('data.settings.create_task', false)->callAction('active')->assertHasErrors(['data.settings.run_workflow']);
        $this->assertSame(App::STATE_CREATED, $this->setting->app->refresh()->status);
        $this->assertFalse($this->setting->refresh()->enabled);

        $page->set('data.settings.create_task', true)->callAction('active')->assertHasNoErrors();
        $this->assertTrue(app(FinderAccess::class)->canRun($this->setting->refresh()));
        $this->assertNotNull($this->setting->app()->value('expires_tariff_at'));
    }

    public function test_old_separate_disabled_flag_can_be_resumed_but_not_by_saving(): void
    {
        $this->setting->update(['enabled' => false]);
        $page = Livewire::test(EditFinder::class, ['record' => $this->setting->id]);
        $page->set('data.enabled', true)->call('save')->assertHasNoErrors();
        $this->assertFalse($this->setting->refresh()->enabled);
        $page->callAction('active')->assertHasNoErrors();
        $this->assertTrue(app(FinderAccess::class)->canRun($this->setting->refresh()));
    }

    public function test_single_button_does_not_extend_expired_access(): void
    {
        $this->setting->update(['active' => false, 'enabled' => false]);
        $this->setting->app->update(['status' => App::STATE_INACTIVE, 'expires_tariff_at' => now()->subDay()->toDateString()]);
        Livewire::test(EditFinder::class, ['record' => $this->setting->id])->callAction('active');
        $this->assertFalse($this->setting->refresh()->enabled);
        $this->assertSame(App::STATE_INACTIVE, $this->setting->app()->value('status'));
    }

    public function test_current_responsible_is_an_explicit_option_and_round_trips_with_selected_staff(): void
    {
        DB::table('amocrm_staffs')->insert(['user_id' => 1, 'staff_id' => 99, 'name' => 'Тестовый сотрудник', 'active' => true]);
        $page = Livewire::test(EditFinder::class, ['record' => $this->setting->id])
            ->assertSet('data.settings.responsible_user_id', 0)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(0, (int) $this->setting->refresh()->settings['responsible_user_id']);
        $page->set('data.settings.responsible_user_id', 99)->call('save')->assertHasNoErrors();
        $this->assertSame(99, (int) $this->setting->refresh()->settings['responsible_user_id']);
        $page->set('data.settings.responsible_user_id', 0)->call('save')->assertHasNoErrors();
        $this->assertSame(0, (int) $this->setting->refresh()->settings['responsible_user_id']);
    }
}
