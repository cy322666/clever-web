<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowInspectorSchema;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowBrowserDatabase;
use Tests\TestCase;

class WorkflowInspectorSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowBrowserDatabase::prepare();
        $this->actingAs(\App\Models\User::findOrFail(1));
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $draft = new \stdClass;
        $draft->rows = [];
        app()->instance('workflow.editor.reference-draft', $draft);
    }

    public function test_export_uses_existing_fields_and_conditional_dependencies(): void
    {
        $schema = app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture,
            \App\Workflows\Actions\WorkflowHttpRequestAction::workflowConfigSchema(), ['body_format'=>'json']);
        $fields = collect($schema)->keyBy('path');
        $this->assertSame('headers', $fields['headers']['kind']);
        $this->assertSame('value', $fields['url']['kind']);
        $this->assertSame('URL', $fields['url']['label']);
        $this->assertSame('body_format', $fields['body']['visible']['dependency']);
        $this->assertContains(['value'=>'json', 'result'=>true], $fields['body']['visible']['cases']);
        $this->assertContains(['value'=>'form', 'result'=>false], $fields['body']['visible']['cases']);
        $this->assertSame('repeater',$fields['form_fields']['kind']);
        $this->assertContains(['value'=>'form','result'=>true],$fields['form_fields']['visible']['cases']);
        $this->assertSame('form_fields.*.name',$fields['form_fields']['children'][0]['path']);
    }

    public function test_delay_bounds_are_exported_to_the_local_numeric_input(): void
    {
        $schema = app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture,
            \App\Workflows\Actions\WorkflowDelayAction::workflowConfigSchema());
        $seconds = collect($schema)->firstWhere('path', 'seconds');
        $this->assertSame(['min' => 0, 'max' => 15], $seconds['inputAttributes']);
        $this->assertSame(1, $seconds['step']);
        $this->assertSame('number', $seconds['type']);
    }

    public function test_responsible_fields_share_native_options_and_keep_multiple_selection(): void
    {
        \Illuminate\Support\Facades\DB::table('amocrm_staffs')->insert([
            ['user_id' => 1, 'staff_id' => 9, 'name' => 'Яна', 'active' => true],
            ['user_id' => 1, 'staff_id' => 10, 'name' => 'Неактивный', 'active' => false],
            ['user_id' => 2, 'staff_id' => 11, 'name' => 'Другой аккаунт', 'active' => true],
        ]);
        $registry = app(ActionRegistry::class);
        $flatten = function (array $rows) use (&$flatten): array {
            return array_merge($rows, ...array_map(fn ($row) => $flatten($row['children'] ?? []), $rows));
        };
        foreach (['amocrm_create_lead', 'amocrm_create_contact', 'amocrm_create_company',
            'amocrm_update_lead_fields', 'amocrm_update_contact_fields', 'amocrm_update_company_fields',
            'amocrm_copy_lead', 'amocrm_create_task', 'amocrm_update_task', 'amocrm_query_leads'] as $type) {
            $schema = app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture,
                $registry->getConfigSchema($type), $registry->getDefaultConfig($type));
            $fields = array_values(array_filter($flatten($schema), fn ($row) => preg_match('/(?:^|\\.)responsible_user_ids?$/', $row['path'])));
            $this->assertNotEmpty($fields, $type);
            foreach ($fields as $field) {
                $this->assertSame('value', $field['kind'], $type);
                $this->assertTrue($field['hasOptions'], $type);
                $this->assertSame([9 => 'Яна'], $field['options'], $type);
                $this->assertSame($type === 'amocrm_query_leads', $field['multiple'], $type);
            }
        }
    }

    public function test_supported_node_schemas_are_serializable_without_executing_nodes(): void
    {
        $registry = app(ActionRegistry::class);
        foreach ($registry->getAllWithMetadata() as $item) {
            if (!($item['available'] ?? true) || in_array($item['type'], [...\App\Workflows\Actions\WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes(), 'run_workflow'], true)) continue;
            $schema = app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture,
                $registry->getConfigSchema($item['type']), $registry->getDefaultConfig($item['type']));
            $this->assertJson(json_encode($schema, JSON_THROW_ON_ERROR), $item['type']);
        }
        $schema = app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture, \App\Workflows\Triggers\ScheduleTrigger::configSchema(), \App\Workflows\Triggers\ScheduleTrigger::defaultConfig());
        $this->assertJson(json_encode($schema, JSON_THROW_ON_ERROR));
    }

    public function test_query_filter_dictionaries_follow_operation_and_pipeline_in_local_schema(): void
    {
        \Illuminate\Support\Facades\DB::table('amocrm_statuses')->insert([
            ['user_id'=>1,'pipeline_id'=>10,'status_id'=>101,'pipeline_name'=>'Основная','name'=>'Новый','active'=>true,'is_archive'=>false],
            ['user_id'=>1,'pipeline_id'=>20,'status_id'=>201,'pipeline_name'=>'Повторная','name'=>'В работе','active'=>true,'is_archive'=>false],
            ['user_id'=>1,'pipeline_id'=>30,'status_id'=>301,'pipeline_name'=>'Архив','name'=>'Архивный','active'=>true,'is_archive'=>true],
            ['user_id'=>2,'pipeline_id'=>40,'status_id'=>401,'pipeline_name'=>'Другой аккаунт','name'=>'Чужой этап','active'=>true,'is_archive'=>false],
        ]);
        \Illuminate\Support\Facades\DB::table('amocrm_staffs')->insert([
            ['user_id'=>1,'staff_id'=>9,'name'=>'Яна','active'=>true],
            ['user_id'=>1,'staff_id'=>8,'name'=>'Неактивный','active'=>false],
            ['user_id'=>2,'staff_id'=>7,'name'=>'Другой аккаунт','active'=>true],
        ]);
        $registry=app(ActionRegistry::class);
        $schema=app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture,
            $registry->getConfigSchema('amocrm_read'),$registry->getDefaultConfig('amocrm_read'));
        $flatten=function(array $rows) use (&$flatten):array {
            return array_merge($rows,...array_map(fn($row)=>$flatten($row['children'] ?? []),$rows));
        };
        $value=collect($flatten($schema))->firstWhere('path','filters.*.value');
        $pipeline=collect($flatten($schema))->firstWhere('path','filters.*.pipeline_id');
        $resolve=function($rule,array $config) use (&$resolve) {
            if (!is_array($rule) || !isset($rule['dependency'],$rule['cases'])) return $rule;
            $selected=data_get($config,str_replace('.*','.0',$rule['dependency']));
            foreach ($rule['cases'] as $case) {
                $matches=$case['value']===null || $selected===null ? $case['value']===$selected : (string)$case['value']===(string)$selected;
                if ($matches) return $resolve($case['result'],$config);
            }
            return $resolve($rule['fallback'] ?? null,$config);
        };
        $config=['operation'=>'leads.list','filters'=>[['field'=>'pipeline_id','operator'=>'eq','value'=>null]]];
        $this->assertSame([10=>'Основная',20=>'Повторная'],$resolve($value['options'],$config));
        $this->assertTrue($resolve($value['hasOptions'],$config));
        $config['filters'][0]=['field'=>'statuses','pipeline_id'=>'10'];
        $this->assertTrue($resolve($pipeline['visible'],$config),'Stage filters expose their pipeline selector');
        $this->assertSame([101=>'Новый'],$resolve($value['options'],$config));
        $config['filters'][0]['pipeline_id']='20';
        $this->assertSame([201=>'В работе'],$resolve($value['options'],$config));
        $config['filters'][0]['pipeline_id']='{{ $json.pipeline_id }}';
        $this->assertSame([],$resolve($value['options'],$config));
        $this->assertFalse($resolve($value['hasOptions'],$config));
        foreach (['leads.list','contacts.list','companies.list'] as $operation) {
            foreach (['responsible_user_id','created_by','updated_by'] as $field) {
                $config=['operation'=>$operation,'filters'=>[['field'=>$field]]];
                $this->assertSame([9=>'Яна'],$resolve($value['options'],$config),$operation.':'.$field);
            }
        }
        $config=['operation'=>'contacts.list','filters'=>[['field'=>'id']]];
        $this->assertFalse($resolve($value['hasOptions'],$config),'Free IDs retain the text input');
        $this->assertFalse($resolve($pipeline['visible'],$config));
    }

    public function test_additional_field_choices_exclude_controls_already_present_in_settings(): void
    {
        foreach (['leads'=>101,'contacts'=>102,'companies'=>103] as $entity=>$id) {
            \Illuminate\Support\Facades\DB::table('amocrm_fields')->insert(['user_id'=>1,'entity_type'=>$entity,'field_id'=>$id,'name'=>'Дополнительное поле','active'=>true]);
        }
        $flatten = function(array $rows) use (&$flatten): array {
            return array_merge($rows,...array_map(fn($row)=>$flatten($row['children'] ?? []),$rows));
        };
        $resolve = function($rule,array $config) use (&$resolve) {
            if (!is_array($rule) || !isset($rule['dependency'],$rule['cases'])) return $rule;
            foreach ($rule['cases'] as $case) if ($case['value'] === data_get($config,$rule['dependency'])) return $resolve($case['result'],$config);
            return $resolve($rule['fallback'] ?? null,$config);
        };
        $registry = app(ActionRegistry::class);
        foreach (['amocrm_create_lead'=>101,'amocrm_create_contact'=>102,'amocrm_create_company'=>103,
            'amocrm_update_lead_fields'=>101,'amocrm_update_contact_fields'=>102,'amocrm_update_company_fields'=>103] as $type=>$id) {
            $defaults=$registry->getDefaultConfig($type);
            $schema=app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture,$registry->getConfigSchema($type),$defaults);
            $field=collect($flatten($schema))->firstWhere('path','fields.*.field');
            $options=$resolve($field['options'],$defaults);
            $this->assertArrayHasKey($id,$options,$type);
            $this->assertArrayNotHasKey('system:responsible_user_id',$options,$type);
            $this->assertArrayNotHasKey('system:name',$options,$type);
            if (str_contains($type,'update')) foreach (array_keys($options) as $key) $this->assertFalse(str_starts_with((string)$key,'system:'),$type);
        }
        $type='amocrm_update_fields';
        $schema=app(WorkflowInspectorSchema::class)->describe(new WorkflowCanvasFixture,\App\Workflows\Actions\AmoCrmUpdateFieldsAction::workflowConfigSchema(),['target_entity'=>'lead']);
        $field=collect($flatten($schema))->firstWhere('path','fields.*.field');
        $this->assertArrayHasKey('system:responsible_user_id',$resolve($field['options'],['target_entity'=>'lead']));
    }

}
