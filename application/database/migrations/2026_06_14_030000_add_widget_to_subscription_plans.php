<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subscription_plans') || Schema::hasColumn('subscription_plans', 'widget')) {
            return;
        }

        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->string('widget')->nullable()->after('slug')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('subscription_plans') || !Schema::hasColumn('subscription_plans', 'widget')) {
            return;
        }

        Schema::table('subscription_plans', function (Blueprint $table): void {
            $table->dropColumn('widget');
        });
    }
};
