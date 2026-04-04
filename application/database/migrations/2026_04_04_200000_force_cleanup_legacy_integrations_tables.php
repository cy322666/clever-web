<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('apps')) {
            $query = DB::table('apps')->whereIn('name', ['marquiz', 'create-lead', 'triggers']);

            if (Schema::hasColumn('apps', 'resource_name')) {
                $query->orWhere(function ($q) {
                    $q->where('resource_name', 'like', '%Marquiz%')
                        ->orWhere('resource_name', 'like', '%Triggers%');
                });
            }

            $query->delete();
        }

        Schema::dropIfExists('marquiz_forms');
        Schema::dropIfExists('marquiz_settings');
        Schema::dropIfExists('create_lead_transactions');
        Schema::dropIfExists('create_lead_settings');
        Schema::dropIfExists('trigger_events');
        Schema::dropIfExists('trigger_settings');
    }

    public function down(): void
    {
        // Intentionally left empty: this is a one-way legacy cleanup migration.
    }
};
