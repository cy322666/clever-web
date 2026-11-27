<?php

namespace Tests\Feature\Vetmanager;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StatusMappingMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('vetmanager_settings', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('webhook_synced_at')->nullable();
            $table->string('target_status')->nullable();
        });
    }

    public function test_existing_target_status_is_copied_to_all_event_mappings(): void
    {
        DB::table('vetmanager_settings')->insert([
            'id' => 1,
            'target_status' => '100.200',
        ]);

        $migration = require database_path('migrations/2026_11_27_120000_add_status_mappings_to_vetmanager_settings.php');
        $migration->up();

        $setting = DB::table('vetmanager_settings')->find(1);

        $this->assertTrue(Schema::hasColumn('vetmanager_settings', 'pipelines'));
        $this->assertSame('100.200', $setting->status_id_wait);
        $this->assertSame('100.200', $setting->status_id_confirm);
        $this->assertSame('100.200', $setting->status_id_came);
        $this->assertSame('100.200', $setting->status_id_cancel);
        $this->assertSame('100.200', $setting->status_id_delete);

        DB::table('vetmanager_settings')->where('id', 1)->update(['status_id_wait' => '300.400']);
        $migration->up();

        $this->assertSame('300.400', DB::table('vetmanager_settings')->value('status_id_wait'));
    }
}
