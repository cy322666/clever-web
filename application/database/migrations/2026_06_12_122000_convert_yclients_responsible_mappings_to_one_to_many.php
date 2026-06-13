<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::rename('yclients_responsible_mappings', 'yclients_responsible_mappings_legacy');

        Schema::create('yclients_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setting_id')->constrained('yclients_settings')->cascadeOnDelete();
            $table->string('company_id');
            $table->string('company_name')->nullable();
            $table->string('yc_user_id');
            $table->string('yc_user_name')->nullable();
            $table->timestamps();

            $table->unique(['setting_id', 'company_id', 'yc_user_id'], 'yclients_user_unique');
        });

        Schema::create('yclients_responsible_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setting_id')->constrained('yclients_settings')->cascadeOnDelete();
            $table->unsignedBigInteger('amo_user_id');
            $table->json('yc_user_keys')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['setting_id', 'amo_user_id'], 'yclients_amo_responsible_unique');
        });

        $legacyRows = DB::table('yclients_responsible_mappings_legacy')->orderBy('id')->get();

        foreach ($legacyRows as $row) {
            DB::table('yclients_users')->insertOrIgnore([
                'setting_id' => $row->setting_id,
                'company_id' => (string)$row->company_id,
                'company_name' => $row->company_name,
                'yc_user_id' => (string)$row->yc_user_id,
                'yc_user_name' => $row->yc_user_name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (empty($row->amo_user_id)) {
                continue;
            }

            $existing = DB::table('yclients_responsible_mappings')
                ->where('setting_id', $row->setting_id)
                ->where('amo_user_id', $row->amo_user_id)
                ->first();
            $keys = $existing ? json_decode((string)$existing->yc_user_keys, true) : [];
            $keys[] = (string)$row->company_id . ':' . (string)$row->yc_user_id;

            DB::table('yclients_responsible_mappings')->updateOrInsert(
                [
                    'setting_id' => $row->setting_id,
                    'amo_user_id' => $row->amo_user_id,
                ],
                [
                    'yc_user_keys' => json_encode(array_values(array_unique($keys))),
                    'active' => $row->active,
                    'created_at' => $existing?->created_at ?? now(),
                    'updated_at' => now(),
                ]
            );
        }

        Schema::drop('yclients_responsible_mappings_legacy');
    }

    public function down(): void
    {
        Schema::dropIfExists('yclients_responsible_mappings');
        Schema::dropIfExists('yclients_users');
    }
};
