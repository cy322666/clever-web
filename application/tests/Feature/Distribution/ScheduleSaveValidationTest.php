<?php

namespace Tests\Feature\Distribution;

use App\Filament\Resources\Integrations\Distribution\ScheduleResource\Pages\ListSchedule;
use App\Models\amoCRM\Staff;
use App\Models\Integrations\Distribution\Scheduler;
use App\Models\Integrations\Distribution\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $this->actingAs($user);

        $component = Livewire::test(ListSchedule::class)
            ->callTableAction('scheduleSave', (string)$staff->id, [
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

        $component->assertHasTableActionErrors();
        $this->assertContains(
            'Исключения: периоды не должны пересекаться.',
            $component->errors()->all()
        );

        $this->assertSame(0, Scheduler::query()->count());
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
