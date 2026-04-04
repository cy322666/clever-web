<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $commands = [
            'install:alfa',
            'install:bizon',
            'install:getcourse',
            'install:tilda',
            'install:active-lead',
            'install:data-info',
            'install:doc',
            'install:distribution',
            'install:analytic',
            'install:yclients',
            'install:import-excel',
            'install:contact-merge',
            'install:call-transcription',
            'install:assistant',
            'install:amo-data',
        ];

        foreach ($commands as $command) {
            Artisan::call($command);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data migration rollback is intentionally empty.
    }
};
