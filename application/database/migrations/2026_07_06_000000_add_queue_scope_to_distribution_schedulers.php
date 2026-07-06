<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('distribution_schedulers', function (Blueprint $table): void {
            $table->string('queue_uuid', 36)->nullable()->after('staff_id');
            $table->integer('template')->nullable()->after('queue_uuid');
            $table->index(['user_id', 'queue_uuid', 'staff_id'], 'distribution_schedulers_queue_staff_idx');
            $table->index(['user_id', 'template', 'staff_id'], 'distribution_schedulers_template_staff_idx');
        });

        $settings = DB::table('distribution_settings')
            ->select(['id', 'user_id', 'settings'])
            ->get();

        foreach ($settings as $setting) {
            $queues = json_decode($setting->settings ?? '[]', true);

            if (!is_array($queues)) {
                continue;
            }

            $queues = array_values(array_filter($queues, static fn(mixed $queue): bool => is_array($queue)));

            if ($queues === []) {
                continue;
            }

            $legacySchedulers = DB::table('distribution_schedulers')
                ->where('user_id', $setting->user_id)
                ->whereNull('queue_uuid')
                ->whereNull('template')
                ->get();

            foreach ($legacySchedulers as $scheduler) {
                foreach ($queues as $index => $queue) {
                    $queueUuid = is_string($queue['queue_uuid'] ?? null) && $queue['queue_uuid'] !== ''
                        ? $queue['queue_uuid']
                        : null;

                    $exists = DB::table('distribution_schedulers')
                        ->where('user_id', $scheduler->user_id)
                        ->where('staff_id', $scheduler->staff_id)
                        ->when(
                            $queueUuid !== null,
                            fn($query) => $query->where('queue_uuid', $queueUuid),
                            fn($query) => $query->whereNull('queue_uuid')->where('template', $index),
                        )
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('distribution_schedulers')->insert([
                        'settings' => $scheduler->settings,
                        'user_id' => $scheduler->user_id,
                        'staff_id' => $scheduler->staff_id,
                        'queue_uuid' => $queueUuid,
                        'template' => $index,
                        'created_at' => $scheduler->created_at,
                        'updated_at' => $scheduler->updated_at,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('distribution_schedulers', function (Blueprint $table): void {
            $table->dropIndex('distribution_schedulers_queue_staff_idx');
            $table->dropIndex('distribution_schedulers_template_staff_idx');
            $table->dropColumn(['queue_uuid', 'template']);
        });
    }
};
