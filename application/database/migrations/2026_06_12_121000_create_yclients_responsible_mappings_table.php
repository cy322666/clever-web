<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('yclients_responsible_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setting_id')->constrained('yclients_settings')->cascadeOnDelete();
            $table->string('company_id');
            $table->string('company_name')->nullable();
            $table->string('yc_user_id');
            $table->string('yc_user_name')->nullable();
            $table->unsignedBigInteger('amo_user_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['setting_id', 'company_id', 'yc_user_id'], 'yclients_responsible_mapping_unique');
        });

        if (!Schema::hasColumn('yclients_settings', 'responsible_mappings')) {
            return;
        }

        DB::table('yclients_settings')
            ->whereNotNull('responsible_mappings')
            ->orderBy('id')
            ->each(function (object $setting): void {
                $rows = json_decode((string)$setting->responsible_mappings, true);

                if (!is_array($rows)) {
                    return;
                }

                foreach ($rows as $row) {
                    if (!is_array($row) || empty($row['company_id']) || empty($row['yc_user_id'])) {
                        continue;
                    }

                    DB::table('yclients_responsible_mappings')->insertOrIgnore([
                        'setting_id' => $setting->id,
                        'company_id' => (string)$row['company_id'],
                        'yc_user_id' => (string)$row['yc_user_id'],
                        'yc_user_name' => $row['yc_user_name'] ?? null,
                        'amo_user_id' => $row['amo_user_id'] ?? null,
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('yclients_responsible_mappings');
    }
};
