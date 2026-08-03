<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_settings', function (Blueprint $table) {
            $table->string('responsible_user_column')->nullable()->after('default_responsible_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('import_settings', function (Blueprint $table) {
            $table->dropColumn('responsible_user_column');
        });
    }
};
