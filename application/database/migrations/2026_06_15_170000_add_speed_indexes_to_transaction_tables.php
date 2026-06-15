<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->addUserCreatedIndex('distribution_transactions', 'distribution_transactions_user_created_idx');
        $this->addUserCreatedIndex('tilda_forms', 'tilda_forms_user_created_idx');
        $this->addUserCreatedIndex('getcourse_orders', 'getcourse_orders_user_created_idx');
        $this->addUserCreatedIndex('getcourse_forms', 'getcourse_forms_user_created_idx');
        $this->addUserCreatedIndex('alfacrm_transactions', 'alfacrm_transactions_user_created_idx');
        $this->addUserCreatedIndex('call_transactions', 'call_transactions_user_created_idx');
        $this->addUserCreatedIndex('import_records', 'import_records_user_created_idx');

        if (
            Schema::hasTable('bizon_webinars')
            && Schema::hasColumn('bizon_webinars', 'user_id')
            && Schema::hasColumn('bizon_webinars', 'created_at')
            && !$this->indexExists('bizon_webinars', 'bizon_webinars_user_created_idx')
        ) {
            Schema::table('bizon_webinars', function (Blueprint $table): void {
                $table->index(['user_id', 'created_at', 'id'], 'bizon_webinars_user_created_idx');
            });
        }
    }

    public function down(): void
    {
        $this->dropIndexIfTableExists('distribution_transactions', 'distribution_transactions_user_created_idx');
        $this->dropIndexIfTableExists('tilda_forms', 'tilda_forms_user_created_idx');
        $this->dropIndexIfTableExists('getcourse_orders', 'getcourse_orders_user_created_idx');
        $this->dropIndexIfTableExists('getcourse_forms', 'getcourse_forms_user_created_idx');
        $this->dropIndexIfTableExists('alfacrm_transactions', 'alfacrm_transactions_user_created_idx');
        $this->dropIndexIfTableExists('call_transactions', 'call_transactions_user_created_idx');
        $this->dropIndexIfTableExists('import_records', 'import_records_user_created_idx');
        $this->dropIndexIfTableExists('bizon_webinars', 'bizon_webinars_user_created_idx');
    }

    private function addUserCreatedIndex(string $tableName, string $indexName): void
    {
        if (
            !Schema::hasTable($tableName)
            || !Schema::hasColumn($tableName, 'user_id')
            || !Schema::hasColumn($tableName, 'created_at')
            || $this->indexExists($tableName, $indexName)
        ) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->index(['user_id', 'created_at', 'id'], $indexName);
        });
    }

    private function dropIndexIfTableExists(string $tableName, string $indexName): void
    {
        if (!Schema::hasTable($tableName) || !$this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->dropIndex($indexName);
        });
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        if (!method_exists(Schema::getFacadeRoot(), 'getIndexes')) {
            return false;
        }

        return collect(Schema::getIndexes($tableName))
            ->contains(fn(array $index): bool => ($index['name'] ?? null) === $indexName);
    }
};
