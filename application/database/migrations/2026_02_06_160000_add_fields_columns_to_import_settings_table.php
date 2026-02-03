<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('import_settings', function (Blueprint $table) {
            $table->json('fields_leads')->nullable()->after('fields_mapping');
            $table->json('fields_contacts')->nullable()->after('fields_leads');
            $table->json('fields_companies')->nullable()->after('fields_contacts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_settings', function (Blueprint $table) {
            $table->dropColumn(['fields_leads', 'fields_contacts', 'fields_companies']);
        });
    }
};
