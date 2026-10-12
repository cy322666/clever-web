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
    ]]);
    if (!preg_match('/wire:snapshot="([^"]+)"/', $html, $match)) throw new RuntimeException('Missing editor snapshot');
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
    $probeSucceeded = true;
    echo "WORKFLOW_INSPECTOR_PROBE_OK partial_render=1 switch=1 draft_preserved=1 external_requests=0 workflow_writes=0 first_open_ms={$firstMs}\n";
} finally {
    Illuminate\Support\Facades\DB::rollBack();
}
