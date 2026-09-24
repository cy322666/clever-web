<?php

namespace Tests\Support;

use App\Filament\Resources\Integrations\Finder\FinderResource;
use App\Models\App;
use App\Models\Integrations\Finder\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinderDatabase
{
    public static function prepare(): Setting
    {
        config(['app.key' => str_repeat('f', 32), 'database.default' => 'finder_test', 'database.connections.finder_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
        DB::purge('finder_test');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('uuid')->nullable();
            $table->string('password')->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('is_root')->default(false);
            $table->timestamps();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('widget')->default('default');
            $table->boolean('active')->default(true);
            $table->string('subdomain')->default('finder-test');
            $table->integer('amo_account_id')->default(123);
            $table->string('zone')->default('ru');
            $table->string('access_token')->nullable();
            $table->string('refresh_token')->nullable();
        });
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('name');
            $table->string('resource_name');
            $table->integer('setting_id')->nullable();
            $table->integer('status');
            $table->date('expires_tariff_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('amocrm_staffs', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->integer('staff_id');
            $table->string('name');
            $table->boolean('active')->default(true);
        });
        (require database_path('migrations/2026_06_09_160732_create_workflows_table.php'))->up();
        (require database_path('migrations/2026_06_09_160733_create_workflow_runs_table.php'))->up();
        (require database_path('migrations/2026_09_24_130000_create_finder_tables.php'))->up();
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Finder Test', 'email' => 'finder@example.test'],
            ['id' => 2, 'name' => 'Other', 'email' => 'other@example.test'],
        ]);
        DB::table('accounts')->insert(['id' => 1, 'user_id' => 1, 'access_token' => 'test', 'refresh_token' => 'test']);
        $setting = Setting::create(['user_id' => 1, 'account_id' => 1, 'active' => true, 'enabled' => true]);
        $setting->update(['settings' => array_replace($setting->options(), ['create_task' => true])]);
        DB::table('apps')->insert(['user_id' => 1, 'name' => 'finder', 'resource_name' => FinderResource::class, 'setting_id' => $setting->id, 'status' => App::STATE_ACTIVE]);
        DB::table('apps')->insert(['user_id' => 1, 'name' => 'workflows', 'resource_name' => \App\Filament\WorkflowBuilder\Resources\WorkflowResource::class, 'status' => App::STATE_ACTIVE]);

        return $setting;
    }
}
