<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tilda_forms', function (Blueprint $table): void {
            if (!Schema::hasColumn('tilda_forms', 'last_error')) {
                $table->text('last_error')->nullable();
            }
            if (!Schema::hasColumn('tilda_forms', 'failed_at')) {
                $table->timestamp('failed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tilda_forms', function (Blueprint $table): void {
            $columns = array_values(array_filter(['last_error', 'failed_at'],
                fn (string $column): bool => Schema::hasColumn('tilda_forms', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
