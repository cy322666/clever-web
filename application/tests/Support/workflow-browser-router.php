<?php

// Isolated local UI fixture. Never uses the project's configured database or real credentials.
// APP_ENV=testing WORKFLOW_BROWSER_TEST_DB=/private/tmp/<file>.sqlite php -S 127.0.0.1:8768 -t public tests/Support/workflow-browser-router.php
if (getenv('APP_ENV') !== 'testing' || ! str_starts_with($_SERVER['REMOTE_ADDR'] ?? '', '127.')) {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($root.'/public/'.$path)) {
    return false;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Tests\Support\WorkflowBrowserDatabase::prepare(getenv('WORKFLOW_BROWSER_TEST_DB') ?: ':memory:');
Illuminate\Support\Facades\File::ensureDirectoryExists('/private/tmp/clever-browser-editor-sessions');
config(['app.url' => 'http://127.0.0.1:8768', 'session.driver' => 'file', 'session.files' => '/private/tmp/clever-browser-editor-sessions',
    'session.cookie' => 'clever_browser_editor_fixture', 'session.secure' => false, 'session.domain' => null, 'queue.default' => 'sync']);
Illuminate\Support\Facades\URL::useOrigin('http://127.0.0.1:8768');
Illuminate\Support\Facades\URL::useAssetOrigin('http://127.0.0.1:8768');
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Auth::guard()->setUser(App\Models\User::findOrFail(1));
Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('app'));
if (! App\Models\Workflows\Workflow::count()) {
    App\Models\Workflows\Workflow::create(['user_id' => 1, 'name' => 'Проверка локального редактора', 'is_active' => false, 'failure_strategy' => 'stop', 'definition' => [
        'trigger' => ['type' => 'manual', 'name' => 'Старт', 'config' => []],
        'actions' => [
            ['id' => 'input', 'type' => 'workflow_set_variable', 'name' => 'Заказы', 'config' => ['assignments' => [['name' => 'orders', 'value' => '[{"id":1,"paid":true},{"id":2,"paid":false}]']]]],
            ['id' => 'loop', 'type' => 'workflow_loop', 'name' => 'Каждый заказ', 'config' => ['items' => '{{ $vars.orders }}', 'mode' => 'once', 'require_return' => true]],
            ['id' => 'condition', 'type' => 'control-condition', 'name' => 'Оплачен?', 'config' => ['logic' => 'and', 'conditions' => [['left' => '{{ $json.paid }}', 'operator' => 'is_true', 'right' => '']]]],
            ['id' => 'read', 'type' => 'amocrm_read', 'name' => 'Получить сделки', 'config' => ['operation' => 'leads.one', 'id' => '{{ $json.id }}', 'body_mode' => 'fields', 'parameters' => []]],
            ['id' => 'http', 'type' => 'http_request', 'name' => 'HTTP-запрос', 'config' => ['method' => 'GET', 'url' => 'https://example.invalid', 'headers' => '{"X-Test":"fixture-only"}', 'body_format' => 'json', 'body' => '', 'timeout' => 15]],
        ],
        'connections' => [
            ['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:input'],
            ['sourceId' => 'action:input', 'sourcePort' => 'output', 'targetId' => 'action:loop'],
            ['sourceId' => 'action:loop', 'sourcePort' => 'each', 'targetId' => 'action:condition'],
            ['sourceId' => 'action:condition', 'sourcePort' => 'yes', 'targetId' => 'action:read'],
            ['sourceId' => 'action:read', 'sourcePort' => 'output', 'targetId' => 'action:loop'],
        ],
        'canvas_positions' => ['trigger' => ['x' => 30, 'y' => 140], 'action:input' => ['x' => 240, 'y' => 140], 'action:loop' => ['x' => 450, 'y' => 140],
            'action:condition' => ['x' => 660, 'y' => 140], 'action:read' => ['x' => 870, 'y' => 60], 'action:http' => ['x' => 660, 'y' => 400]],
    ]]);
}
Illuminate\Support\Facades\Route::get('/__workflow-browser-preview', function () {
    $html = Livewire\Livewire::mount(App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\BrowserEditWorkflow::class, ['record' => 1]);

    return Illuminate\Support\Facades\Blade::render('<x-filament-panels::layout.base><div style="height:100vh">{!! $editor !!}</div></x-filament-panels::layout.base>', ['editor' => $html]);
})->middleware('web');
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
