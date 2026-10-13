<?php

// Read-only rendering probe: no workflows are executed or persisted.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::fake();

$count = 0;
foreach ([
    app(Leek\FilamentWorkflows\Actions\ActionRegistry::class)->getAllWithMetadata(),
    app(Leek\FilamentWorkflows\Triggers\TriggerRegistry::class)->getAllWithMetadata(),
] as $group => $entries) {
    foreach ($entries as $entry) {
        $appearance = $group === 0
            ? App\Services\Workflows\WorkflowNodeAppearance::action($entry['type'], [], $entry['icon'] ?? '')
            : App\Services\Workflows\WorkflowNodeAppearance::trigger($entry['type'], $entry['icon'] ?? '');
        if ($group === 1 && $appearance['tone'] !== 'trigger') {
            throw new RuntimeException('Start node color is inconsistent: '.$entry['type']);
        }
        $html = Illuminate\Support\Facades\Blade::render('<x-workflow-node-icon :appearance="$appearance"/>', compact('appearance'));
        if (!str_contains($html, 'workflow-node-artwork') && !str_contains($html, 'data-node-language="javascript"') && (!str_contains($html, '<svg') || !str_contains($html, 'workflow-node-glyph'))) {
            throw new RuntimeException('Node icon did not render: '.$entry['type']);
        }
        $count++;
    }
}
$html = Illuminate\Support\Facades\Blade::render('<x-filament-workflows::workflows.action-card :action="$action" :read-only="true"/>', [
    'action' => ['id'=>'probe','type'=>'amocrm_create_contact','config'=>[]],
]);
if (!str_contains($html, 'data-node-family="crm"') || !str_contains($html, 'data-node-tone="create"') || !str_contains($html, 'data-node-artwork="contact-create"')) {
    throw new RuntimeException('Node card appearance is missing');
}
echo "WORKFLOW_NODE_APPEARANCE_OK icons={$count} external_requests=0 workflow_writes=0\n";
