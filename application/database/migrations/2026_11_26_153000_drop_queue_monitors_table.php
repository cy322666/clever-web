<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('queue_monitors');
    }

    public function down(): void
    {
        if (! Schema::hasTable('queue_monitors')) {
            (require __DIR__.'/2026_04_04_234246_create_filament-jobs-monitor_table.php')->up();
        }
    }
};
