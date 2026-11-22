<?php

namespace Tests\Feature\Core;

use App\Filament\Resources\Cases\CompanyCaseResource;
use App\Filament\Resources\Core\AuthenticationLogResource;
use App\Models\Cases\CompanyCase;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Rappasoft\LaravelAuthenticationLog\Models\AuthenticationLog;
use Tests\TestCase;

class AdministrativeResourceAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'session.driver' => 'array']);
        DB::purge('sqlite');
        Http::preventStrayRequests();

        Schema::create('authentication_log', function (Blueprint $table): void {
            $table->id();
            $table->string('authenticatable_type');
            $table->unsignedBigInteger('authenticatable_id');
        });
        Schema::create('company_cases', fn (Blueprint $table) => $table->id());
        DB::table('authentication_log')->insert([
            ['authenticatable_type' => User::class, 'authenticatable_id' => 42],
            ['authenticatable_type' => User::class, 'authenticatable_id' => 99],
        ]);
        DB::table('company_cases')->insert(['id' => 1]);
    }

    public function test_guests_and_regular_users_cannot_list_or_mutate_administrative_records(): void
    {
        foreach ([null, $this->user(false)] as $user) {
            Auth::forgetGuards();
            if ($user) {
                Auth::setUser($user);
            }

            foreach ([AuthenticationLogResource::class => AuthenticationLog::class, CompanyCaseResource::class => CompanyCase::class] as $resource => $model) {
                $record = (new $model)->forceFill(['id' => 1]);
                foreach (['viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'forceDelete', 'restore', 'replicate', 'reorder'] as $ability) {
                    $this->assertFalse($resource::getAuthorizationResponse($ability, $record)->allowed(), $resource.':'.$ability);
                }
                $this->assertSame(0, $resource::getEloquentQuery()->count());
            }
        }
    }

    public function test_root_can_use_the_administrative_resources(): void
    {
        Auth::setUser($this->user(true));
        $this->assertTrue(AuthenticationLogResource::canViewAny());
        $this->assertSame(2, AuthenticationLogResource::getEloquentQuery()->count());
        $this->assertTrue(CompanyCaseResource::canCreate());
        $this->assertTrue(CompanyCaseResource::canEdit((new CompanyCase)->forceFill(['id' => 1])));
        $this->assertSame(1, CompanyCaseResource::getEloquentQuery()->count());
    }

    public function test_regular_users_are_denied_by_the_registered_pages(): void
    {
        $this->actingAs($this->user(false));
        foreach (['/panel/authentication-logs', '/panel/company-cases', '/panel/company-cases/create'] as $path) {
            $this->get($path)->assertForbidden();
        }
        // Record resolution must not reveal an administrative record to a regular user.
        $this->get('/panel/company-cases/1/edit')->assertNotFound();
    }

    public function test_existing_edit_actions_are_reauthorized_on_the_server(): void
    {
        Auth::setUser($this->user(false));
        $this->expectException(AuthorizationException::class);
        CompanyCaseResource::authorizeEdit((new CompanyCase)->forceFill(['id' => 1]));
    }

    public function test_personal_login_history_remains_available_through_the_user_relation(): void
    {
        $user = $this->user(false);
        Auth::setUser($user);
        $this->assertSame(1, $user->authentications()->count());
        $this->assertSame(0, AuthenticationLogResource::getEloquentQuery()->count());
    }

    private function user(bool $root): User
    {
        $user = (new User)->forceFill(['id' => 42, 'name' => 'Audit user', 'email' => 'audit@example.test',
            'is_root' => $root, 'active' => true, 'crm_provider' => 'amocrm', 'onboarding_completed_at' => now()]);
        $user->exists = true;

        return $user;
    }
}
