<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_settings', function (Blueprint $table) {
            $table->string('lead_note_column')->nullable()->after('lead_tag_column');
        });
    }

    public function down(): void
    {
        Schema::table('import_settings', function (Blueprint $table) {
            $table->dropColumn('lead_note_column');
        });
    }
};
