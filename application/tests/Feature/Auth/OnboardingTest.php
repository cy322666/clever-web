<?php

namespace Tests\Feature\Auth;

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\Onboarding;
use App\Http\Middleware\EnsureOnboardingCompleted;
use App\Models\User;
use App\Support\Filament\PanelRedirect;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'onboarding_test',
            'database.connections.onboarding_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        DB::purge('onboarding_test');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('locale', 5)->default('ru');
            $table->string('crm_provider', 20)->nullable();
            $table->string('industry', 40)->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_incomplete_user_is_sent_to_the_three_question_tour(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->assertSame(Onboarding::getUrl(), PanelRedirect::dashboardUrl());
        $this->get(Onboarding::getUrl())->assertOk();

        $request = Request::create('/panel/dashboard');
        $request->setUserResolver(fn (): User => $user);
        $response = app(EnsureOnboardingCompleted::class)->handle(
            $request,
            fn (): Response => new Response('dashboard'),
        );

        $this->assertTrue($response->isRedirect(Onboarding::getUrl()));
    }

    public function test_user_can_save_language_crm_and_industry(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        Livewire::test(Onboarding::class)
            ->set('language', 'en')
            ->set('crm', 'kommo')
            ->set('industry', 'beauty')
            ->call('complete')
            ->assertHasNoErrors()
            ->assertRedirect(Dashboard::getUrl());

        $user->refresh();
        $this->assertSame('en', $user->locale);
        $this->assertSame('kommo', $user->crm_provider);
        $this->assertSame('beauty', $user->industry);
        $this->assertNotNull($user->onboarding_completed_at);
        $this->assertFalse($user->needsOnboarding());
    }

    private function user(): User
    {
        return User::withoutEvents(fn (): User => User::query()->create([
            'uuid' => fake()->uuid(),
            'name' => 'New user',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'active' => true,
        ]));
    }
}
