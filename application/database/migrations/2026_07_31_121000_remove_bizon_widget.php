<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('apps')) {
            DB::table('apps')
                ->where('name', 'bizon')
                ->delete();
        }

        if (Schema::hasTable('accounts') && Schema::hasColumn('accounts', 'widget')) {
            DB::table('accounts')
                ->where('widget', 'bizon')
                ->delete();
        }

        if (Schema::hasTable('widget_subscriptions')) {
            DB::table('widget_subscriptions')
                ->where('widget', 'bizon')
                ->delete();
        }

        if (Schema::hasTable('subscription_invoice_requests')) {
            DB::table('subscription_invoice_requests')
                ->where('widget', 'bizon')
                ->delete();
        }

        if (Schema::hasTable('subscription_plans') && Schema::hasColumn('subscription_plans', 'widget')) {
            DB::table('subscription_plans')
                ->where('widget', 'bizon')
                ->delete();
        }

        if (Schema::hasTable('widgets')) {
            DB::table('widgets')
                ->whereIn('slug', ['bizon', 'bizon-365'])
                ->orWhere('title', 'Бизон 365')
                ->delete();
        }

        Schema::dropIfExists('bizon_forms');
        Schema::dropIfExists('bizon_viewers');
        Schema::dropIfExists('bizon_webinars');
        Schema::dropIfExists('bizon_settings');
    }

    public function down(): void
    {
        // Widget removal is intentionally irreversible: code and data are deleted together.
    }
};
