<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $connection = (string)config('queue.failed.database', config('database.default'));
        $table = (string)config('queue.failed.table', 'failed_jobs');

        if (!Schema::connection($connection)->hasTable($table)) {
            return;
        }

        DB::connection($connection)
            ->table($table)
            ->where('failed_at', '<', now()->subMinutes(10))
            ->delete();
    }

    public function down(): void
    {
        // Deleted failed-job payloads cannot be restored safely.
    }
};
