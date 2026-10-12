<?php

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Services\Workflows\WorkflowExpressionCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkflowAutocompleteTest extends TestCase
{
    public function test_field_suggestions_are_local_active_and_tenant_scoped(): void
    {
        WorkflowEditorSaveStateTest::prepareDatabase();
        Http::preventStrayRequests();
        $this->assertSame([], WorkflowExpressionCatalog::customFieldSuggestions());
        $this->actingAs(User::findOrFail(2));
        DB::table('amocrm_fields')->insert([
            ['user_id'=>2,'field_id'=>12345,'name'=>'Школа','entity_type'=>'contacts','active'=>true],
            ['user_id'=>2,'field_id'=>12399,'name'=>'Заказ','entity_type'=>'leads','active'=>true],
            ['user_id'=>1,'field_id'=>12300,'name'=>'Чужое поле','entity_type'=>'contacts','active'=>true],
            ['user_id'=>2,'field_id'=>12301,'name'=>'Удалённое поле','entity_type'=>'contacts','active'=>false],
        ]);
        $this->assertSame([
            ['id'=>'12345','label'=>'Школа','entity'=>'contacts'],
            ['id'=>'12399','label'=>'Заказ','entity'=>'leads'],
        ], WorkflowExpressionCatalog::customFieldSuggestions());
        Http::assertNothingSent();
    }
}
