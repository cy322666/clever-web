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
            $table->string('default_sale')->nullable()->after('default_lead_name');
            $table->string('contact_name')->nullable()->after('default_sale');
            $table->string('company_name')->nullable()->after('contact_name');
            $table->string('lead_name')->nullable()->after('company_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_settings', function (Blueprint $table) {
            $table->dropColumn(['default_sale', 'contact_name', 'company_name', 'lead_name']);
        });
    }
};
