<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('widgets') && Schema::hasColumn('widgets', 'trial_days')) {
            DB::table('widgets')
                ->where('slug', 'workflows')
                ->update(['trial_days' => 7]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('widgets') && Schema::hasColumn('widgets', 'trial_days')) {
            DB::table('widgets')
                ->where('slug', 'workflows')
                ->where('trial_days', 7)
                ->update(['trial_days' => 14]);
        }
    }
};
