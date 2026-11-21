<?php

use App\Support\Integrations\RetiredPlatformDataCleanup;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(RetiredPlatformDataCleanup::class)->apply();
    }

    public function down(): void
    {
        throw new RuntimeException('Retired platform data must be restored from its verified backup, not recreated as empty tables.');
    }
};
