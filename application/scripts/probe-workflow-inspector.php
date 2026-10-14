<?php

$probeSucceeded = false;
register_shutdown_function(static function () use (&$probeSucceeded): void {
    if (!$probeSucceeded) exit(1);
});

// Real Livewire/Filament rendering without persisted workflows or external calls.
// Run from application/: php scripts/probe-workflow-inspector.php
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once $root.'/tests/Support/WorkflowCanvasFixture.php';

config(['cache.default' => 'array', 'session.driver' => 'array']);
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::fake();
if ($app->environment('testing') && config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:') {
    require_once $root.'/tests/Support/WorkflowCanvasDatabase.php';
    Tests\Support\WorkflowCanvasDatabase::prepare();
}
Illuminate\Support\Facades\DB::beginTransaction();
if (Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
    Illuminate\Support\Facades\DB::statement('SET TRANSACTION READ ONLY');
}

try {
    Livewire\Livewire::component('workflow-inspector-deploy-probe', Tests\Support\WorkflowCanvasFixture::class);
    $html = Livewire\Livewire::mount('workflow-inspector-deploy-probe', ['workflowActions' => [
        ['id' => 'probe-a', 'name' => 'Probe A', 'type' => 'workflow_delay', 'config' => ['seconds' => 5]],
        ['id' => 'probe-b', 'name' => 'Probe B', 'type' => 'workflow_delay', 'config' => ['seconds' => 8]],
        ['id' => 'probe-loop', 'name' => 'Probe Loop', 'type' => 'workflow_loop', 'config' => ['items' => '[10,20,30]', 'mode' => 'all']],
        ['id' => 'probe-code', 'name' => 'Probe Code', 'type' => 'workflow_javascript', 'config' => ['javascript_code' => 'return $json;']],
    ]]);
    if (!preg_match('/wire:snapshot="([^"]+)"/', $html, $match)) throw new RuntimeException('Missing editor snapshot');
    if (!str_contains($html, "mode === 'variables' ? 'Справочники' : 'Каталог нод'") || !str_contains($html, 'aria-label="Справочники"') || str_contains($html, 'workflow-node-library__rail')) {
        throw new RuntimeException('Editor must expose one context catalog and a separate references control');
    }
    if (substr_count($html, 'data-workflow-reference-toggle') !== 2 || !str_contains($html, 'x-bind:data-workflow-reference-panel')) {
        throw new RuntimeException('References must be available from both the toolbar and mounted inspector');
    }
    if (str_contains($html, 'workflow-manual-run') || method_exists(App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow::class, 'runWorkflowManually')
        || method_exists(App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow::class, 'pollManualWorkflowRun')) {
        throw new RuntimeException('Standalone editor run must be removed, including its server entrypoints');
    }
    if (!str_contains($html, 'x-bind:hidden="!opened" hidden') || str_contains($html, 'has-inspector')) {
        throw new RuntimeException('Canvas space must follow the actual inspector visibility');
    }
    if (!str_contains($html, 'class="workflow-canvas-grid"') || !str_contains($html, 'x-bind:pattern-transform.camel="gridTransform()"') || str_contains($html, 'gridStyle()')) {
        throw new RuntimeException('Canvas must use the vector grid with the same pan and zoom as the nodes');
    }
    if (!str_contains($html, 'aria-label="Создать область этапа"') || !str_contains($html, 'x-for="area in canvasAreas()"')) {
        throw new RuntimeException('Manual canvas areas or their creation control are missing');
    }
    $areas = App\Services\Workflows\WorkflowCanvasAreas::normalize([
        ['id' => 'probe_area', 'title' => 'Контакт', 'x' => -40, 'y' => 24, 'width' => 640, 'height' => 360],
    ]);
    $areaDefinition = ['trigger' => ['type' => 'manual', 'config' => []], 'actions' => [], 'canvas_areas' => $areas];
    $areaRoundtrip = App\Services\Workflows\WorkflowTransfer::decode(App\Services\Workflows\WorkflowTransfer::encode('Probe', $areaDefinition));
    if ($areaRoundtrip['definition']['canvas_areas'] !== $areas) throw new RuntimeException('Canvas areas failed export/import');
    $snapshot = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
    request()->headers->set('X-Livewire', 'true');
    $update = function (string $method, array $params = [], array $updates = []) use (&$snapshot): array {
        [$snapshot, $effects] = app(Livewire\Mechanisms\HandleComponents\HandleComponents::class)
            ->update($snapshot, $updates, [['path' => '', 'method' => $method, 'params' => $params]]);
        return $effects;
    };
    $started = microtime(true);
    $effects = $update('openWorkflowInspector', ['action:probe-a']);
    $firstMs = (int) round((microtime(true) - $started) * 1000);
    $form = implode('', $effects['partials'] ?? []);
    if (isset($effects['html']) || !str_contains($form, 'workflow-inspector__form') || str_contains($form, 'x-trap')) {
        throw new RuntimeException('Settings must use a partial inspector, not a modal or full canvas render');
    }
    $effects = $update('openWorkflowInspector', ['action:probe-b'], ['mountedActions.0.data.seconds' => 12]);
    $form = implode('', $effects['partials'] ?? []);
    if (isset($effects['html']) || !str_contains($form, 'data-inspector-node="action:probe-b"')) {
        throw new RuntimeException('Node switch did not replace the form');
    }
    $effects = $update('openWorkflowInspector', ['action:probe-a']);
    // Livewire encodes arrays as [value, metadata]; no production payloads are printed.
    $mounted = $snapshot['data']['mountedActions'][0][0][0] ?? [];
    $value = $mounted['data'][0]['seconds'] ?? null;
    if ((int) $value !== 12) throw new RuntimeException('Node switch lost the previous draft');
    $update('callMountedAction');
    if (($snapshot['data']['mountedActions'][0] ?? null) !== []) throw new RuntimeException('Inspector failed to apply and close');
    $effects = $update('openWorkflowInspector', ['action:probe-code']);
    $form = implode('', $effects['partials'] ?? []);
    if (!str_contains($form, 'workflow-javascript-editor') || !str_contains($form, 'codeEditorFormComponent') || !str_contains($form, 'Форматировать')) {
        throw new RuntimeException('JavaScript code editor is missing');
    }
    $update('callMountedAction');
    $effects = $update('openWorkflowInspector', ['action:probe-loop']);
    $form = implode('', $effects['partials'] ?? []);
    if (!str_contains($form, 'Указать количество') || !str_contains($form, 'Количество проходов')) {
        throw new RuntimeException('Loop iteration limit controls are missing');
    }
    $update('callMountedAction', [], ['mountedActions.0.data.mode' => 'limit', 'mountedActions.0.data.limit' => 2]);
    $update('openWorkflowInspector', ['action:probe-loop']);
    $config = $snapshot['data']['mountedActions'][0][0][0]['data'][0] ?? [];
    if (($config['mode'] ?? null) !== 'limit' || (int) ($config['limit'] ?? 0) !== 2) {
        throw new RuntimeException('Loop iteration limit was not preserved');
    }
    $result = (new App\Workflows\Actions\WorkflowLoopAction)->handle(['items' => [10, 20, 30], 'mode' => 'limit', 'limit' => 2]);
    if (($result['output']['items'] ?? null) !== [10, 20]) throw new RuntimeException('Loop iteration limit was not applied');
    $loopDefinition = ['trigger' => ['type' => 'manual'], 'actions' => [
        ['id' => 'loop', 'type' => 'workflow_loop', 'config' => ['items' => [['id' => 10], ['id' => 20], ['id' => 30]], 'mode' => 'limit', 'limit' => 2]],
        ['id' => 'body', 'type' => 'workflow_set_variable', 'config' => ['assignments' => [['name' => 'item_id', 'value' => '{{ $json.id }}']]]],
        ['id' => 'done', 'type' => 'workflow_set_variable', 'config' => ['assignments' => [['name' => 'finished', 'value' => true]]]],
    ], 'connections' => [
        ['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:loop'],
        ['sourceId' => 'action:loop', 'sourcePort' => 'each', 'targetId' => 'action:body'],
        ['sourceId' => 'action:body', 'sourcePort' => 'output', 'targetId' => 'action:loop'],
        ['sourceId' => 'action:loop', 'sourcePort' => 'done', 'targetId' => 'action:done'],
    ]];
    $debugger = app(App\Workflows\Engine\WorkflowDebugger::class);
    $loopPreview = $debugger->executeNode($loopDefinition['actions'][0], [], [], null, null, false, $loopDefinition);
    if ($loopPreview['status'] !== 'completed' || array_column($loopPreview['results'], 'id') !== ['loop']
        || App\Services\Workflows\WorkflowOutputView::value($loopPreview['results'][0]['output']) !== ['id' => 10]) {
        throw new RuntimeException('Single loop node must emit only the first item without running its branch');
    }
    $nextPreview = $debugger->executeNode($loopDefinition['actions'][1], $loopPreview['context'], [], null, null, false, $loopDefinition);
    if ($nextPreview['status'] !== 'completed' || ($nextPreview['results'][0]['output']['item_id'] ?? null) !== 10) {
        throw new RuntimeException('The next manual node did not receive the selected loop item');
    }
    $fullRun = $debugger->start($loopDefinition, [], null, null);
    while ($fullRun['status'] === 'ready') $fullRun = $debugger->advance($fullRun);
    if ($fullRun['status'] !== 'completed' || array_column($fullRun['results'], 'id') !== ['loop', 'body', 'body', 'done']) {
        throw new RuntimeException('Whole-flow iteration behavior changed');
    }
    $effects = $update('selectTriggerType', ['schedule', true]);
    $scheduleId = $snapshot['data']['editingTriggerNodeId'] ?? '';
    if (!$scheduleId || !str_contains($effects['html'] ?? '', 'data-workflow-node-id="'.$scheduleId.'"')
        || !str_contains($effects['html'] ?? '', 'data-inspector-node="'.$scheduleId.'"')
        || !str_contains($effects['html'] ?? '', 'Часовой пояс')
        || !in_array('workflow-node-created', array_column($effects['dispatches'] ?? [], 'name'), true)) {
        throw new RuntimeException('New schedule must render its card and settings and close the catalog in one response');
    }
    $probeSucceeded = true;
    echo "WORKFLOW_INSPECTOR_PROBE_OK partial_render=1 switch=1 draft_preserved=1 loop_limit=1 single_loop_node=1 schedule_insert=1 external_requests=0 workflow_writes=0 first_open_ms={$firstMs}\n";
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
