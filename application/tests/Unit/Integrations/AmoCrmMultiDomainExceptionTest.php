<?php

namespace Tests\Unit\Integrations;

use App\Http\Controllers\Api\AuthController;
use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AmoCrmMultiDomainExceptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'amo_multi_domain_test',
            'database.connections.amo_multi_domain_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'services.amocrm.widgets.import-excel.multi_domain_subdomains' => ['071026cleverexcel'],
        ]);
        DB::purge('amo_multi_domain_test');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget')->nullable();
            $table->unsignedBigInteger('amo_account_id')->nullable();
            $table->string('subdomain')->nullable();
            $table->string('zone')->nullable();
            $table->boolean('active')->default(false);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
        });
        DB::table('users')->insert(['id' => 1]);
    }

    public function test_allowed_excel_domain_can_coexist_with_the_main_domain(): void
    {
        $this->account('workflows', '280926techscenario');
        $excel = $this->account('import-excel', '071026cleverexcel');

        $this->assertTrue($excel->exists);
        $this->assertCount(2, Account::query()->where('user_id', 1)->get());
    }

    public function test_other_excel_domain_remains_blocked(): void
    {
        $this->account('workflows', '280926techscenario');

        $this->expectException(ValidationException::class);
        $this->account('import-excel', 'another-excel');
    }

    public function test_allowed_domain_does_not_bypass_cross_user_ownership_check(): void
    {
        DB::table('users')->insert(['id' => 2]);
        DB::table('accounts')->insert([
            'user_id' => 2,
            'widget' => 'import-excel',
            'subdomain' => '071026cleverexcel',
            'zone' => 'ru',
            'active' => true,
        ]);

        $method = new \ReflectionMethod(AuthController::class, 'validateAmoSubdomainAllowedForUser');
        $error = $method->invoke(new AuthController, User::query()->findOrFail(1), '071026cleverexcel', 'import-excel');

        $this->assertSame('Этот amoCRM домен уже подключен к другому аккаунту платформы.', $error);
    }

    private function account(string $widget, string $subdomain): Account
    {
        $account = new Account;
        $account->forceFill([
            'user_id' => 1,
            'widget' => $widget,
            'subdomain' => $subdomain,
            'zone' => 'ru',
            'active' => true,
            'access_token' => 'token-'.$widget,
        ])->save();

        return $account;
    }
}
