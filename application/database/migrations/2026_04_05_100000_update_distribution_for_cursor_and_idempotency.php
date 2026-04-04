<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('distribution_settings', function (Blueprint $table) {
            $table->json('cursors')->nullable()->after('settings');
        });

        Schema::table('distribution_transactions', function (Blueprint $table) {
            $table->string('event_key', 64)->nullable()->after('body');
            $table->string('queue_uuid', 36)->nullable()->after('template');

            $table->unique(
                ['user_id', 'event_key'],
                'distribution_transactions_user_event_key_unique'
            );

            $table->index(
                ['user_id', 'queue_uuid', 'status', 'created_at', 'id'],
                'distribution_transactions_queue_lookup_idx'
            );

            $table->index(
                ['user_id', 'template', 'status', 'created_at', 'id'],
                'distribution_transactions_template_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('distribution_transactions', function (Blueprint $table) {
            $table->dropUnique('distribution_transactions_user_event_key_unique');
            $table->dropIndex('distribution_transactions_queue_lookup_idx');
            $table->dropIndex('distribution_transactions_template_lookup_idx');
            $table->dropColumn(['event_key', 'queue_uuid']);
        });

        Schema::table('distribution_settings', function (Blueprint $table) {
            $table->dropColumn('cursors');
        });
    }
};

