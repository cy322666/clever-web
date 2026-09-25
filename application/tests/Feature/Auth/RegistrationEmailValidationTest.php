<?php

namespace Tests\Feature\Auth;

use App\Filament\App\Auth\Register;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationEmailValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'registration_email_test',
            'database.connections.registration_email_test' => [
                'driver' => 'sqlite', 'database' => ':memory:',
            ],
        ]);
        DB::purge('registration_email_test');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_registration_rejects_an_email_without_a_public_domain(): void
    {
        Livewire::test(Register::class)
            ->fillForm([
                'name' => 'Test User',
                'email' => 'test@test',
                'password' => 'strong-password',
                'passwordConfirmation' => 'strong-password',
            ])
            ->call('register')
            ->assertHasFormErrors(['email']);

        $this->assertDatabaseCount('users', 0);
    }
}
