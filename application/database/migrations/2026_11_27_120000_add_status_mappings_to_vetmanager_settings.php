<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STATUS_COLUMNS = [
        'status_id_cancel',
        'status_id_wait',
        'status_id_came',
        'status_id_confirm',
        'status_id_delete',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('vetmanager_settings', 'pipelines')) {
            Schema::table('vetmanager_settings', function (Blueprint $table): void {
                $table->json('pipelines')->nullable()->after('webhook_synced_at');
            });
        }

        foreach (self::STATUS_COLUMNS as $column) {
            if (! Schema::hasColumn('vetmanager_settings', $column)) {
                Schema::table('vetmanager_settings', function (Blueprint $table) use ($column): void {
                    $table->string($column, 20)->nullable()->after('target_status');
                });
            }
        }

        DB::table('vetmanager_settings')
            ->select(['id', 'target_status', ...self::STATUS_COLUMNS])
            ->whereNotNull('target_status')
            ->orderBy('id')
            ->each(function (object $setting): void {
                $values = [];

                foreach (self::STATUS_COLUMNS as $column) {
                    if (blank($setting->{$column})) {
                        $values[$column] = $setting->target_status;
                    }
                }

                if ($values !== []) {
                    DB::table('vetmanager_settings')->where('id', $setting->id)->update($values);
                }
            });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            [...self::STATUS_COLUMNS, 'pipelines'],
            fn (string $column): bool => Schema::hasColumn('vetmanager_settings', $column),
        ));

        if ($columns !== []) {
            Schema::table('vetmanager_settings', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
