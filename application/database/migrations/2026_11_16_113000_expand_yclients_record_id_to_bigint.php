<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yclients_records', function (Blueprint $table): void {
            $table->bigInteger('record_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('yclients_records')->where('record_id', '>', 2147483647)
            ->orWhere('record_id', '<', -2147483648)->exists()) {
            throw new RuntimeException('Cannot narrow YClients record IDs without losing data.');
        }

        Schema::table('yclients_records', function (Blueprint $table): void {
            $table->integer('record_id')->nullable()->change();
        });
    }
};
