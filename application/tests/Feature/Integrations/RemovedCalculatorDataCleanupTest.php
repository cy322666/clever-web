<?php

namespace Tests\Feature\Integrations;

use App\Support\Integrations\RemovedCalculatorDataCleanup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RemovedCalculatorDataCleanupTest extends TestCase
{
    private const ACTION = 'amocrm_calculate_field';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'calculator_cleanup_test',
            'database.connections.calculator_cleanup_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('calculator_cleanup_test');
    }

    public function test_removes_only_calculator_nodes_and_edges_from_current_and_legacy_definitions(): void
    {
        $this->createDefinitionTables();
        $note = ['id' => 'note', 'type' => 'amocrm_add_note', 'config' => [
            'text' => self::ACTION, 'payload' => ['type' => self::ACTION], 'empty' => (object)[],
        ]];
        $definition = ['trigger' => ['type' => 'manual', 'config' => (object)[]], 'actions' => [
            $this->calculator('top'),
            ['id' => 'condition', 'type' => 'control-condition', 'config' => ['true_actions' => [
                $this->calculator('nested'), $note,
            ], 'false_actions' => [
                ['id' => 'legacy', 'type' => 'condition', 'config' => null, 'properties' => [
                    'false_actions' => [$this->calculator('legacy-calculator'), $note],
                ]],
            ]]],
            ['id' => 'suffix', 'type' => self::ACTION . '_other', 'config' => (object)[]],
        ], 'connections' => [
            $this->edge('trigger', 'action:top'), $this->edge('action:top', 'action:condition'),
            $this->edge('action:condition', 'action:nested', 'yes'), $this->edge('action:nested', 'action:note'),
            $this->edge('action:legacy', 'action:legacy-calculator', 'no'),
            $this->edge('action:note', 'action:suffix'),
        ]];

        foreach (['workflows', 'workflow_templates'] as $table) {
            DB::table($table)->insert(['id' => 1, 'is_active' => true, 'definition' => json_encode($definition)]);
            DB::table($table)->insert(['id' => 2, 'is_active' => true, 'definition' => json_encode(['actions' => [$note]], JSON_PRETTY_PRINT)]);
            DB::table($table)->insert(['id' => 3, 'is_active' => true, 'definition' => '{invalid-json']);
        }
        $untouched = DB::table('workflows')->where('id', 2)->first();

        (new RemovedCalculatorDataCleanup)->run();

        $expected = $definition;
        array_shift($expected['actions']);
        array_shift($expected['actions'][0]['config']['true_actions']);
        array_shift($expected['actions'][0]['config']['false_actions'][0]['properties']['false_actions']);
        $expected['connections'] = [$this->edge('action:note', 'action:suffix')];
        foreach (['workflows', 'workflow_templates'] as $table) {
            $this->assertSame(json_encode($expected), DB::table($table)->where('id', 1)->value('definition'));
            $this->assertSame(0, DB::table($table)->where('id', 1)->value('is_active'));
            $this->assertEquals($untouched, DB::table($table)->where('id', 2)->first());
            $this->assertSame('{invalid-json', DB::table($table)->where('id', 3)->value('definition'));
            $this->assertSame(1, DB::table($table)->where('id', 3)->value('is_active'));
        }
    }

    public function test_cleans_run_snapshots_and_exact_step_history_without_deleting_neighbour_data(): void
    {
        $this->createDefinitionTables();
        $this->createRunTables();
        // Today's calculator ID used to belong to a different action in an older run.
        DB::table('workflows')->insert(['id' => 1, 'is_active' => true, 'definition' => json_encode([
            'actions' => [$this->calculator('reused')],
        ])]);
        $note = ['id' => 'note', 'type' => 'amocrm_add_note', 'config' => ['text' => 'Keep me']];
        $context = [
            'trigger_data' => ['type' => self::ACTION, 'payload' => 'Keep unrelated trigger data'],
            'step_outputs' => ['calc' => ['result' => 42], 'note' => ['payload' => self::ACTION]],
            'variables' => [
                '_definition_snapshot' => ['actions' => [$this->calculator('calc'), $note], 'connections' => [
                    $this->edge('trigger', 'action:calc'), $this->edge('action:calc', 'action:note'),
                ]],
                '_resolved_inputs' => ['calc' => ['formula' => '40+2'], 'note' => ['text' => 'Keep me']],
                '_node_names' => ['Calculation' => ['calc'], 'Shared label' => ['calc', 'note'], 'Empty' => []],
                'custom' => ['type' => self::ACTION],
            ],
        ];
        $this->insertRun(1, $context, 'completed');
        $legacyContext = ['step_outputs' => ['legacy-calc' => ['result' => 9], 'keep' => ['value' => 'yes']]];
        $this->insertRun(2, $legacyContext, 'paused');
        $untouchedContext = ['step_outputs' => ['reused' => ['value' => 'historical note']], 'variables' => [
            '_definition_snapshot' => ['actions' => [['id' => 'reused', 'type' => 'amocrm_add_note', 'config' => (object)[]]]],
        ]];
        $this->insertRun(3, $untouchedContext, 'running');
        $this->insertRun(4, ['variables' => ['_definition_snapshot' => ['actions' => [$this->calculator('pending-calc')]]]], 'pending');
        DB::table('workflow_run_steps')->insert([
            ['id' => 1, 'workflow_run_id' => 1, 'step_id' => 'calc', 'action_type' => self::ACTION, 'output_data' => '{"result":42}'],
            ['id' => 2, 'workflow_run_id' => 1, 'step_id' => 'note', 'action_type' => 'amocrm_add_note', 'output_data' => '{"type":"' . self::ACTION . '"}'],
            ['id' => 3, 'workflow_run_id' => 2, 'step_id' => 'legacy-calc', 'action_type' => self::ACTION, 'output_data' => '{}'],
            ['id' => 4, 'workflow_run_id' => 2, 'step_id' => 'keep', 'action_type' => self::ACTION . '_other', 'output_data' => '{}'],
            ['id' => 5, 'workflow_run_id' => 3, 'step_id' => 'reused', 'action_type' => 'amocrm_add_note', 'output_data' => '{}'],
        ]);
        DB::table('workflow_run_entities')->insert([
            ['id' => 1, 'workflow_run_step_id' => 1, 'entity_id' => 41],
            ['id' => 2, 'workflow_run_step_id' => 2, 'entity_id' => 42],
            ['id' => 3, 'workflow_run_step_id' => 3, 'entity_id' => 43],
            ['id' => 4, 'workflow_run_step_id' => null, 'entity_id' => 44],
        ]);
        DB::table('workflow_amo_crm_mutations')->insert([
            ['id' => 1, 'action_type' => self::ACTION],
            ['id' => 2, 'action_type' => self::ACTION . '_other'],
            ['id' => 3, 'action_type' => 'amocrm_add_note'],
        ]);
        $untouchedRun = DB::table('workflow_runs')->where('id', 3)->first();
        $untouchedSteps = DB::table('workflow_run_steps')->whereIn('id', [2, 4, 5])->orderBy('id')->get();

        (new RemovedCalculatorDataCleanup)->run();

        unset($context['step_outputs']['calc'], $context['variables']['_resolved_inputs']['calc'], $context['variables']['_node_names']['Calculation']);
        $context['variables']['_node_names']['Shared label'] = ['note'];
        $context['variables']['_definition_snapshot']['actions'] = [$note];
        $context['variables']['_definition_snapshot']['connections'] = [];
        $this->assertSame($context, $this->runContext(1));
        $this->assertSame('completed', DB::table('workflow_runs')->where('id', 1)->value('status'));
        $this->assertSame(['step_outputs' => ['keep' => ['value' => 'yes']]], $this->runContext(2));
        foreach ([2, 4] as $id) {
            $this->assertSame('cancelled', DB::table('workflow_runs')->where('id', $id)->value('status'));
            $this->assertNull(DB::table('workflow_runs')->where('id', $id)->value('scheduled_resume_at'));
        }
        $this->assertEquals($untouchedRun, DB::table('workflow_runs')->where('id', 3)->first());
        $this->assertEquals($untouchedSteps, DB::table('workflow_run_steps')->orderBy('id')->get());
        $this->assertSame([2, 4], DB::table('workflow_run_entities')->orderBy('id')->pluck('id')->all());
        $this->assertSame([2, 3], DB::table('workflow_amo_crm_mutations')->orderBy('id')->pluck('id')->all());
        $this->assertSame(4, DB::table('workflow_runs')->count());

        $before = $this->snapshot();
        (new RemovedCalculatorDataCleanup)->run();
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_handles_missing_tables_and_legacy_columns(): void
    {
        (new RemovedCalculatorDataCleanup)->run();
        Schema::create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->text('definition');
        });
        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->text('payload')->nullable();
        });
        Schema::create('workflow_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->string('action_type');
        });
        Schema::create('workflow_run_entities', function (Blueprint $table): void {
            $table->id();
            $table->text('payload');
        });
        DB::table('workflows')->insert(['definition' => json_encode(['actions' => [$this->calculator('only')]])]);
        DB::table('workflow_run_steps')->insert([['action_type' => self::ACTION], ['action_type' => 'amocrm_add_note']]);
        DB::table('workflow_run_entities')->insert(['payload' => 'Unrelated legacy entity']);

        (new RemovedCalculatorDataCleanup)->run();

        $this->assertSame('{"actions":[]}', DB::table('workflows')->value('definition'));
        $this->assertSame(['amocrm_add_note'], DB::table('workflow_run_steps')->pluck('action_type')->all());
        $this->assertSame('Unrelated legacy entity', DB::table('workflow_run_entities')->value('payload'));
    }

    private function createDefinitionTables(): void
    {
        foreach (['workflows', 'workflow_templates'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->boolean('is_active');
                $table->text('definition');
            });
        }
    }

    private function createRunTables(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('workflow_id');
            $table->text('context_data')->nullable();
            $table->string('status');
            $table->timestamp('scheduled_resume_at')->nullable();
        });
        Schema::create('workflow_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->string('step_id');
            $table->string('action_type');
            $table->text('output_data');
        });
        Schema::create('workflow_run_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_run_step_id')->nullable()->constrained('workflow_run_steps')->nullOnDelete();
            $table->integer('entity_id');
        });
        Schema::create('workflow_amo_crm_mutations', function (Blueprint $table): void {
            $table->id();
            $table->string('action_type');
        });
    }

    private function calculator(string $id): array
    {
        return ['id' => $id, 'type' => self::ACTION, 'config' => ['expression' => '1 + 2']];
    }

    private function edge(string $from, string $to, string $port = 'output'): array
    {
        return ['sourceId' => $from, 'sourcePort' => $port, 'targetId' => $to];
    }

    private function insertRun(int $id, array $context, string $status): void
    {
        DB::table('workflow_runs')->insert([
            'id' => $id, 'workflow_id' => 1, 'context_data' => json_encode($context, JSON_PRETTY_PRINT),
            'status' => $status, 'scheduled_resume_at' => '2026-09-13 10:00:00',
        ]);
    }

    private function runContext(int $id): array
    {
        return json_decode(DB::table('workflow_runs')->where('id', $id)->value('context_data'), true);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['workflows', 'workflow_templates', 'workflow_runs', 'workflow_run_steps', 'workflow_run_entities', 'workflow_amo_crm_mutations'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get();
        }

        return $snapshot;
    }
}
