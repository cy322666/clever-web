<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkflowListDatabase
{
    public static function prepare(string $database = ':memory:'): void
    {
        WorkflowCanvasDatabase::prepare($database);

        if (Schema::hasTable('workflows')) {
            return;
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('uuid')->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
        });
        (require database_path('migrations/2026_06_09_160732_create_workflows_table.php'))->up();
        (require database_path('migrations/2026_06_13_130000_add_group_name_to_workflows_table.php'))->up();
        (require database_path('migrations/2026_09_11_160000_create_workflow_folders_table.php'))->up();
        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->integer('workflow_id');
            $table->timestamps();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('widget');
            $table->boolean('active')->default(false);
            $table->string('subdomain')->nullable();
            $table->string('access_token')->nullable();
            $table->string('refresh_token')->nullable();
        });

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Тестовый пользователь', 'email' => 'workflow@example.test'],
            ['id' => 2, 'name' => 'Другой пользователь', 'email' => 'other@example.test'],
        ]);
    }
}
