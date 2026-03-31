<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('amocrm_statuses', function (Blueprint $table) {
            $table->integer('sort')
                ->nullable()
                ->after('color');

            $table->boolean('is_closed')
                ->default(false)
                ->index()
                ->after('is_main');

            $table->boolean('is_won')
                ->default(false)
                ->index()
                ->after('is_closed');

            $table->boolean('is_lost')
                ->default(false)
                ->index()
                ->after('is_won');
        });
    }

    public function down(): void
    {
        Schema::table('amocrm_statuses', function (Blueprint $table) {
            $table->dropColumn([
                'sort',
                'is_closed',
                'is_won',
                'is_lost',
            ]);
        });
    }
};
