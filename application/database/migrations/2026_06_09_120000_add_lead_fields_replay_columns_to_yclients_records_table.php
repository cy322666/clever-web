<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('yclients_records', function (Blueprint $table) {
            $table->string('lead_fields_replay_status')->nullable()->after('error_message');
            $table->timestamp('lead_fields_replayed_at')->nullable()->after('lead_fields_replay_status');
            $table->text('lead_fields_replay_error')->nullable()->after('lead_fields_replayed_at');
        });
    }

    public function down(): void
    {
        Schema::table('yclients_records', function (Blueprint $table) {
            $table->dropColumn([
                'lead_fields_replay_status',
                'lead_fields_replayed_at',
                'lead_fields_replay_error',
            ]);
        });
    }
};
