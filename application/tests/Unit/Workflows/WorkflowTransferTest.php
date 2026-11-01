<?php

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowTransfer;
use App\Services\Workflows\WorkflowFolders;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowIdentity;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowTransferTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); WorkflowListDatabase::prepare(); $this->actingAs(User::findOrFail(1)); Http::preventStrayRequests(); }
    private function definition(): array
    {
        return ['version'=>2,'trigger'=>['type'=>'manual','config'=>[]],'tags'=>['Продажи'],'description'=>'Пример',
            'actions'=>[['id'=>'http','type'=>'http_request','config'=>['url'=>'https://example.test','method'=>'GET','headers'=>'{"Authorization":"Bearer secret"}']]],
            'connections'=>[['sourceId'=>'trigger','sourcePort'=>'output','targetId'=>'action:http']]];
    }
    public function test_roundtrip_creates_a_new_draft_with_graph_layout_and_one_owned_folder(): void
    {
        WorkflowFolders::create('Продажи');
        $json = WorkflowTransfer::encode('Мой поток',$this->definition(),['action:http'=>['x'=>12,'y'=>25]]);
        $this->assertStringNotContainsString('Bearer secret',$json);
        $first = WorkflowTransfer::import($json,'Продажи');
        $second = WorkflowTransfer::import($json);
        $this->assertNotSame($first->id,$second->id);
        $this->assertSame(1,$first->user_id);
        $this->assertFalse($first->is_active);
        $this->assertSame('Продажи',$first->group_name);
        $this->assertNull($second->group_name);
        $this->assertEquals(['action:http'=>['x'=>12,'y'=>25]],$first->definition['canvas_layout']);
        $this->assertArrayNotHasKey('tags',$first->definition);
        $this->assertSame($this->definition()['connections'],$first->definition['connections']);
        Http::assertNothingSent();
    }
    public function test_foreign_owner_activation_and_credentials_from_file_are_never_imported(): void
    {
        $file = json_decode(WorkflowTransfer::encode('Импорт',$this->definition()),true);
        $file += ['id'=>123,'user_id'=>2,'is_active'=>true];
        $file['definition']['actions'][0]['config']['bot_token_encrypted']='secret';
        $flow = WorkflowTransfer::import(json_encode($file));
        $this->assertSame(1,$flow->user_id);
        $this->assertFalse($flow->is_active);
        $this->assertArrayNotHasKey('bot_token_encrypted',$flow->definition['actions'][0]['config']);
    }

    public function test_retired_workflow_tags_are_ignored_without_removing_tags_from_node_data(): void
    {
        $definition = $this->definition();
        $definition['actions'][0]['config']['body'] = ['tags' => ['VIP']];
        $file = json_decode(WorkflowTransfer::encode('Поток', $definition), true);
        $this->assertArrayNotHasKey('tags', $file['definition']);
        $this->assertSame(['VIP'], $file['definition']['actions'][0]['config']['body']['tags']);

        $file['definition']['tags'] = ['Старый тег сценария'];
        $decoded = WorkflowTransfer::decode(json_encode($file));
        $this->assertArrayNotHasKey('tags', $decoded['definition']);
        $this->assertSame(['VIP'], $decoded['definition']['actions'][0]['config']['body']['tags']);
        Http::assertNothingSent();
    }
    public function test_absolute_coordinates_roundtrip_and_invalid_coordinates_are_rejected(): void
    {
        $definition = $this->definition();
        $definition['canvas_positions'] = ['action:http'=>['x'=>720,'y'=>-160]];
        $decoded = WorkflowTransfer::decode(WorkflowTransfer::encode('Позиции',$definition));
        $this->assertEquals($definition['canvas_positions'],$decoded['definition']['canvas_positions']);
        $file = json_decode(WorkflowTransfer::encode('Позиции',$definition),true);
        $file['definition']['canvas_positions']['action:http']['x'] = 100001;
        $this->expectException(\InvalidArgumentException::class);
        WorkflowTransfer::decode(json_encode($file));
    }
    public function test_edge_routes_roundtrip_and_saved_workflow_preserves_them(): void
    {
        $definition = $this->definition();
        $key = '["trigger","output","action:http"]';
        foreach ([['kind'=>'curve','x'=>420,'y'=>-80], ['kind'=>'loop','exitX'=>740,'entryX'=>-24,'y'=>310]] as $route) {
            $definition['canvas_edge_routes'] = [$key => $route];
            $flow = WorkflowTransfer::import(WorkflowTransfer::encode('Маршрут', $definition));
            $this->assertEquals([$key => $route], $flow->fresh()->definition['canvas_edge_routes']);
            $this->assertSame($definition['connections'], $flow->definition['connections']);
        }
    }
    public function test_invalid_edge_routes_are_rejected_without_creating_workflows(): void
    {
        $file = json_decode(WorkflowTransfer::encode('Маршрут', $this->definition()), true);
        foreach ([
            ['bad' => ['kind'=>'curve','x'=>1,'y'=>2]],
            ['["trigger","output","action:http"]' => ['kind'=>'curve','x'=>100001,'y'=>2]],
            ['["trigger","output","action:http"]' => ['kind'=>'loop','y'=>2]],
            ['["trigger","output","action:http"]' => ['kind'=>'unknown','x'=>1,'y'=>2]],
        ] as $routes) {
            $file['definition']['canvas_edge_routes'] = $routes;
            try { WorkflowTransfer::import(json_encode($file)); $this->fail('Invalid route accepted'); }
            catch (\InvalidArgumentException $error) { $this->assertNotEmpty($error->getMessage()); }
        }
        $this->assertSame(0, Workflow::count());
    }
    public function test_invalid_graph_unknown_nodes_and_format_are_rejected_without_writes(): void
    {
        $file = json_decode(WorkflowTransfer::encode('Импорт',$this->definition()),true);
        $unknown = $file; $unknown['definition']['actions'][0]['type']='unknown';
        $cycle = $file; $cycle['definition']['connections'][]=['sourceId'=>'action:http','sourcePort'=>'output','targetId'=>'action:http'];
        foreach (['{', '{}', json_encode($unknown), json_encode($cycle), str_repeat('x',2097153)] as $json) {
            try { WorkflowTransfer::import($json); $this->fail('Invalid import accepted'); }
            catch (\JsonException|\InvalidArgumentException $error) { $this->assertNotEmpty($error->getMessage()); }
        }
        $this->assertSame(0,Workflow::count());
    }
    public function test_identity_changes_preserve_unsaved_actions_and_saved_activation(): void
    {
        $flow = WorkflowTransfer::import(WorkflowTransfer::encode('Старое',$this->definition()));
        $editor = new class($flow) {
            use HasWorkflowIdentity;
            public array $data=[]; public array $definition=[];
            public function __construct(private Workflow $record) { $this->definition=$record->definition; }
            public function getRecord(): Workflow { return $this->record; }
            protected function syncDefinition(): void {}
        };
        $editor->definition['actions'][0]['config']['url']='https://unsaved.test';
        $editor->renameWorkflow('Новое');
        WorkflowFolders::create('Папка A'); WorkflowFolders::create('Папка B');
        $editor->setWorkflowFolder('Папка A'); $editor->setWorkflowFolder('Папка B');
        $fresh = $flow->fresh();
        $this->assertSame('Новое',$editor->data['name']);
        $this->assertSame('Папка B',$editor->data['group_name']);
        $this->assertSame('Старое',$fresh->name);
        $this->assertNull($fresh->group_name);
        $this->assertFalse($fresh->is_active);
        $this->assertSame('https://example.test',$fresh->definition['actions'][0]['config']['url']);
        $this->assertSame('https://unsaved.test',$editor->definition['actions'][0]['config']['url']);
        $editor->setWorkflowFolder(null); $this->assertNull($flow->fresh()->group_name);
        $this->actingAs(User::findOrFail(2));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $editor->renameWorkflow('Чужое');
    }
}
