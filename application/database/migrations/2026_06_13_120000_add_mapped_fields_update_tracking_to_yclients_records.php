<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('yclients_records', function (Blueprint $table): void {
            $table->timestamp('mapped_fields_updated_at')->nullable()->after('lead_fields_replay_error');
            $table->text('mapped_fields_update_error')->nullable()->after('mapped_fields_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('yclients_records', function (Blueprint $table): void {
            $table->dropColumn([
                'mapped_fields_updated_at',
                'mapped_fields_update_error',
            ]);
        });
    }
};
