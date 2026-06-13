<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->string('group_name')->nullable()->after('description');
            $table->index('group_name', 'workflows_group_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropIndex('workflows_group_name_index');
            $table->dropColumn('group_name');
        });
    }
};
