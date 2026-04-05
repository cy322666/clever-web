<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    private const ASSISTANT_RESOURCE = 'App\\Filament\\Resources\\Integrations\\AssistantResource';

    public function up(): void
    {
        $this->deduplicateAppsByUserAndName();
        $this->deduplicateAssistantSettingsByUser();
        $this->ensureUniqueUserUuids();

        $this->assertNoDuplicates('accounts', ['user_id']);
        $this->assertNoDuplicates('apps', ['user_id', 'name']);
        $this->assertNoDuplicates('assistant_settings', ['user_id']);
        $this->assertNoDuplicates('users', ['uuid'], notNullOnly: true);

        Schema::table('users', function (Blueprint $table) {
            $table->unique('uuid', 'users_uuid_unique');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->unique('user_id', 'accounts_user_id_unique');
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->unique(['user_id', 'name'], 'apps_user_id_name_unique');
        });

        Schema::table('assistant_settings', function (Blueprint $table) {
            $table->unique('user_id', 'assistant_settings_user_id_unique');
        });
    }

    public function down(): void
    {
        $this->dropUniqueSafely('assistant_settings', 'assistant_settings_user_id_unique');
        $this->dropUniqueSafely('apps', 'apps_user_id_name_unique');
        $this->dropUniqueSafely('accounts', 'accounts_user_id_unique');
        $this->dropUniqueSafely('users', 'users_uuid_unique');
    }

    private function deduplicateAppsByUserAndName(): void
    {
        $duplicates = DB::table('apps')
            ->select('user_id', 'name')
            ->groupBy('user_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $ids = DB::table('apps')
                ->where('user_id', $duplicate->user_id)
                ->where('name', $duplicate->name)
                ->orderByDesc('id')
                ->pluck('id')
                ->all();

            $keepId = (int)array_shift($ids);
            $dropIds = array_map('intval', $ids);

            if ($dropIds === []) {
                continue;
            }

            if (Schema::hasTable('webhooks')) {
                DB::table('webhooks')
                    ->whereIn('app_id', $dropIds)
                    ->update(['app_id' => $keepId]);
            }

            DB::table('apps')
                ->whereIn('id', $dropIds)
                ->delete();
        }
    }

    private function deduplicateAssistantSettingsByUser(): void
    {
        $duplicates = DB::table('assistant_settings')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $ids = DB::table('assistant_settings')
                ->where('user_id', $duplicate->user_id)
                ->orderByDesc('id')
                ->pluck('id')
                ->all();

            $keepId = (int)array_shift($ids);
            $dropIds = array_map('intval', $ids);

            if ($dropIds === []) {
                continue;
            }

            if (Schema::hasTable('assistant_chat_sessions')) {
                DB::table('assistant_chat_sessions')
                    ->whereIn('assistant_setting_id', $dropIds)
                    ->update(['assistant_setting_id' => $keepId]);
            }

            if (Schema::hasTable('assistant_logs')) {
                DB::table('assistant_logs')
                    ->whereIn('assistant_setting_id', $dropIds)
                    ->update(['assistant_setting_id' => $keepId]);
            }

            DB::table('apps')
                ->where('name', 'assistant')
                ->where('resource_name', self::ASSISTANT_RESOURCE)
                ->whereIn('setting_id', $dropIds)
                ->update(['setting_id' => $keepId]);

            DB::table('assistant_settings')
                ->whereIn('id', $dropIds)
                ->delete();
        }
    }

    private function ensureUniqueUserUuids(): void
    {
        $duplicateUuids = DB::table('users')
            ->select('uuid')
            ->whereNotNull('uuid')
            ->groupBy('uuid')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('uuid');

        foreach ($duplicateUuids as $uuid) {
            $userIds = DB::table('users')
                ->where('uuid', $uuid)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            array_shift($userIds);

            foreach ($userIds as $userId) {
                DB::table('users')
                    ->where('id', $userId)
                    ->update(['uuid' => (string)Str::uuid()]);
            }
        }
    }

    private function assertNoDuplicates(string $table, array $columns, bool $notNullOnly = false): void
    {
        $query = DB::table($table)->select($columns);

        if ($notNullOnly && count($columns) === 1) {
            $query->whereNotNull($columns[0]);
        }

        $hasDuplicates = $query
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException(
                sprintf(
                    'Cannot add unique constraint for %s(%s): duplicate rows still exist.',
                    $table,
                    implode(',', $columns),
                )
            );
        }
    }

    private function dropUniqueSafely(string $table, string $index): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($index) {
                $tableBlueprint->dropUnique($index);
            });
        } catch (Throwable) {
            // no-op
        }
    }
};
