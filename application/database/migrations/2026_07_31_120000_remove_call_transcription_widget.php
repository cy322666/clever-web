<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('apps')) {
            DB::table('apps')
                ->where('name', 'call-transcription')
                ->delete();
        }

        if (Schema::hasTable('accounts') && Schema::hasColumn('accounts', 'widget')) {
            DB::table('accounts')
                ->where('widget', 'call-transcription')
                ->delete();
        }

        if (Schema::hasTable('widget_subscriptions')) {
            DB::table('widget_subscriptions')
                ->where('widget', 'call-transcription')
                ->delete();
        }

        if (Schema::hasTable('subscription_invoice_requests')) {
            DB::table('subscription_invoice_requests')
                ->where('widget', 'call-transcription')
                ->delete();
        }

        if (Schema::hasTable('subscription_plans') && Schema::hasColumn('subscription_plans', 'widget')) {
            DB::table('subscription_plans')
                ->where('widget', 'call-transcription')
                ->delete();
        }

        if (Schema::hasTable('widgets')) {
            DB::table('widgets')
                ->whereIn('slug', ['call-transcription', 'ai-call-control'])
                ->orWhere('title', 'ИИ Контроль звонков')
                ->delete();
        }

        Schema::dropIfExists('call_transactions');
        Schema::dropIfExists('call_transcription_settings');
    }

    public function down(): void
    {
        // Widget removal is intentionally irreversible: code and data are deleted together.
    }
};
