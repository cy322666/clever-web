<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkflowCanvasDatabase
{
    public static function prepare(string $database = ':memory:'): void
    {
        app()->instance(\App\Services\Workflows\WorkflowSubscriptionAccess::class, new class
        {
            public function canUse(int $userId): bool { return true; }

            public function activationIssue(int $userId): ?string { return null; }

            public function assertCanExecute(int $userId): void {}

            public function deactivateForUser(int $userId): int { return 0; }
        });

        if ($database !== ':memory:') {
            new \PDO('sqlite:' . $database);
        }
        config([
            'database.default' => 'workflow_canvas_test',
            'database.connections.workflow_canvas_test' => ['driver' => 'sqlite', 'database' => $database],
        ]);
        DB::purge('workflow_canvas_test');

        if (Schema::hasTable('amocrm_staffs')) {
            return;
        }

        Schema::create('amocrm_staffs', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->integer('staff_id')->nullable();
            $table->string('name');
            $table->boolean('active')->default(true);
        });
        Schema::create('amocrm_statuses', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
            $table->integer('pipeline_id')->nullable();
            $table->integer('status_id')->nullable();
            $table->string('pipeline_name');
            $table->string('name');
            $table->integer('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->boolean('is_archive')->default(false);
        });
    }
}
