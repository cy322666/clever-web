<?php

namespace Tests\Feature\Distribution;

use App\Models\amoCRM\Staff;
use App\Models\Integrations\Distribution\Scheduler;
use App\Models\Integrations\Distribution\Setting;
use App\Models\User;
use App\Filament\Resources\Integrations\Distribution\ScheduleResource\Widgets\DistributionScheduleCalendar;
use App\Services\Distribution\ScheduleSettingsService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ScheduleSaveValidationTest extends TestCase
{
    public function test_schedule_save_shows_validation_error_for_overlapping_exceptions(): void
    {
        $user = User::withoutEvents(function (): User {
            return User::query()->create([
                'name' => 'Test User',
                'email' => 'schedule-validation@example.com',
                'password' => 'secret',
            ]);
        });

        Setting::query()->create([
            'user_id' => $user->id,
            'active' => true,
            'settings' => '{}',
        ]);

        $staff = Staff::query()->create([
            'user_id' => $user->id,
            'staff_id' => 101,
            'name' => 'Manager',
            'active' => true,
            'login' => 'manager@example.com',
        ]);

        $payload = app(ScheduleSettingsService::class)->buildPayload([
            'advanced_mode' => false,
            'quick_preset' => 'always',
            'timezone' => 'Europe/Moscow',
            'exceptions' => [
                [
                    'from' => '2026-04-06 10:00:00',
                    'to' => '2026-04-06 12:00:00',
                    'type' => 'work',
                ],
                [
                    'from' => '2026-04-06 11:00:00',
                    'to' => '2026-04-06 13:00:00',
                    'type' => 'free',
                ],
            ],
        ]);

        try {
            app(ScheduleSettingsService::class)->saveForStaff($staff, $payload);
            $this->fail('Validation exception was not thrown.');
        } catch (ValidationException $exception) {
            $errors = collect($exception->errors())->flatten()->all();
        }

        $this->assertContains(
            'Исключения: периоды не должны пересекаться.',
            $errors
        );

        $this->assertSame(0, Scheduler::query()->count());
    }

    public function test_calendar_configure_schedule_action_saves_staff_schedule(): void
    {
        $user = User::withoutEvents(function (): User {
            return User::query()->create([
                'name' => 'Test User',
                'email' => 'schedule-action@example.com',
                'password' => 'secret',
            ]);
        });

        Setting::query()->create([
            'user_id' => $user->id,
            'active' => true,
            'settings' => '{}',
        ]);

        $staff = Staff::query()->create([
            'user_id' => $user->id,
            'staff_id' => 102,
            'name' => 'Manager',
            'active' => true,
            'login' => 'manager-action@example.com',
        ]);

        $this->actingAs($user);

        Livewire::test(DistributionScheduleCalendar::class)
            ->callAction('configureSchedule', [
                'staff_id' => $staff->id,
                'advanced_mode' => false,
                'quick_preset' => 'always',
                'timezone' => 'Europe/Moscow',
                'exceptions' => [],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, Scheduler::query()->count());
        $this->assertSame('always', json_decode(Scheduler::query()->first()->settings, true)['mode']);
    }

    public function test_calendar_hides_free_exceptions_and_splits_work_periods(): void
    {
        $user = User::withoutEvents(function (): User {
            return User::query()->create([
                'name' => 'Test User',
                'email' => 'schedule-free-exception@example.com',
                'password' => 'secret',
            ]);
        });

        Setting::query()->create([
            'user_id' => $user->id,
            'active' => true,
            'settings' => '{}',
        ]);

        $staff = Staff::query()->create([
            'user_id' => $user->id,
            'staff_id' => 103,
            'name' => 'Manager',
            'active' => true,
            'login' => 'manager-free-exception@example.com',
        ]);

        app(ScheduleSettingsService::class)->saveForStaff($staff, app(ScheduleSettingsService::class)->buildPayload([
            'advanced_mode' => true,
            'mode' => 'weekly',
            'timezone' => 'Europe/Moscow',
            'weekly_rules' => [
                [
                    'day' => 1,
                    'from' => '08:00:00',
                    'to' => '18:00:00',
                ],
            ],
            'exceptions' => [
                [
                    'from' => '2026-04-06 13:00:00',
                    'to' => '2026-04-06 14:00:00',
                    'type' => 'free',
                ],
            ],
        ]));

        $this->actingAs($user);

        $component = Livewire::test(DistributionScheduleCalendar::class)->instance();
        $method = new \ReflectionMethod($component, 'eventsForStaff');
        $events = $method->invoke(
            $component,
            $staff->fresh(['schedule']),
            Carbon::parse('2026-04-06 00:00:00', 'Europe/Moscow'),
            Carbon::parse('2026-04-07 00:00:00', 'Europe/Moscow'),
        );

        $this->assertSame([
            ['Смена', '08:00', '13:00'],
            ['Смена', '14:00', '18:00'],
        ], collect($events)
            ->map(fn($event): array => [
                $event->getTitle(),
                $event->getStart()->format('H:i'),
                $event->getEnd()->format('H:i'),
            ])
            ->values()
            ->all());
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('uuid')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_root')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('distribution_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->longText('settings')->nullable();
            $table->longText('cursors')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('user_id');
            $table->timestamps();
        });

        Schema::create('amocrm_staffs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('name')->nullable();
            $table->integer('staff_id')->nullable();
            $table->integer('group_id')->nullable();
            $table->string('group_name')->nullable();
            $table->boolean('active')->default(true);
            $table->string('login')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('admin')->default(false);
            $table->timestamps();
        });

        Schema::create('distribution_schedulers', function (Blueprint $table) {
            $table->increments('id');
            $table->longText('settings')->nullable();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('staff_id');
            $table->timestamps();
        });

        Filament::setCurrentPanel('app');
        Filament::setServingStatus();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('distribution_schedulers');
        Schema::dropIfExists('amocrm_staffs');
        Schema::dropIfExists('distribution_settings');
        Schema::dropIfExists('users');

        parent::tearDown();
    }
}
