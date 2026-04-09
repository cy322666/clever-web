<?php

use App\Models\Core\Account;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('accounts')) {
            return;
        }

        if (!Schema::hasColumn('accounts', 'widget')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->string('widget')->default(Account::DEFAULT_WIDGET)->after('user_id');
            });
        }

        DB::table('accounts')
            ->whereNull('widget')
            ->orWhere('widget', '')
            ->update(['widget' => Account::DEFAULT_WIDGET]);

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('widget')->default(Account::DEFAULT_WIDGET)->change();
        });

        $this->dropUniqueIfExists('accounts', 'accounts_user_id_unique');
        $this->dropUniqueIfExists('accounts', 'accounts_user_widget_unique');

        $duplicatePairs = DB::table('accounts')
            ->select(['user_id', 'widget'])
            ->groupBy('user_id', 'widget')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicatePairs) {
            throw new RuntimeException(
                'Cannot add accounts_user_widget_unique: duplicate [user_id, widget] pairs already exist in accounts.'
            );
        }

        if (!$this->indexExists('accounts', 'accounts_user_widget_unique')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->unique(['user_id', 'widget'], 'accounts_user_widget_unique');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('accounts')) {
            return;
        }

        $this->dropUniqueIfExists('accounts', 'accounts_user_widget_unique');
        $this->dropUniqueIfExists('accounts', 'accounts_user_id_unique');

        $duplicateUsers = DB::table('accounts')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateUsers) {
            throw new RuntimeException(
                'Cannot rollback widget-scoped amo accounts: multiple accounts per user already exist.'
            );
        }

        if (Schema::hasColumn('accounts', 'widget')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropColumn('widget');
            });
        }

        if (!$this->indexExists('accounts', 'accounts_user_id_unique')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->unique('user_id', 'accounts_user_id_unique');
            });
        }
    }

    private function dropUniqueIfExists(string $table, string $index): void
    {
        if (!$this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($index) {
            $tableBlueprint->dropUnique($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'pgsql' => (bool)DB::selectOne(
                'select 1 from pg_indexes where schemaname = current_schema() and tablename = ? and indexname = ? limit 1',
                [$table, $index]
            ),
            'mysql' => (bool)DB::selectOne(
                'select 1 from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? limit 1',
                [$table, $index]
            ),
            'sqlite' => (bool)DB::selectOne(
                "select 1 from sqlite_master where type = 'index' and tbl_name = ? and name = ? limit 1",
                [$table, $index]
            ),
            default => false,
        };
    }
};
