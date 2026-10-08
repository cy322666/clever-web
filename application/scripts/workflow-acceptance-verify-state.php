<?php

// Read-only post-run verification; emits only IDs/counts/booleans, not contact data or secrets.
$root = getenv('WORKFLOW_APP_ROOT') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory = storage_path('app/private/workflow-acceptance');
$state = json_decode(file_get_contents($directory.'/state-15.json'), true, flags: JSON_THROW_ON_ERROR);
$workflow = App\Models\Workflows\Workflow::withoutGlobalScopes()->findOrFail(15);
$account = App\Models\User::findOrFail($workflow->user_id)->resolveAmoAccountForWidget('workflows');
if ($account->subdomain !== 'widgetscenario' || (int)$account->user_id !== (int)$workflow->user_id) throw new RuntimeException('Account scope mismatch');
$client = new App\Services\amoCRM\Client($account);
$result = ['state' => $state['phase'], 'fixtures' => $state['fixtures']];
foreach (['leads','contacts','companies'] as $entity) {
    $data = $client->requestV4('GET', '/api/v4/'.$entity, [], ['limit'=>250]);
    $rows = $data['_embedded'][$entity] ?? [];
    $result[$entity] = count($rows);
    if ($entity === 'leads') $result['open_leads'] = count(array_filter($rows, fn ($item) => !in_array($item['status_id'], [142,143], true)));
}
$observer = App\Models\Workflows\Workflow::withoutGlobalScopes()->findOrFail($state['fixtures']['observer_workflow_id']);
$expectedDefinition=['trigger'=>['type'=>'generic-webhook','config'=>['source'=>'webhook']],
    'actions'=>[['id'=>'acceptance','type'=>'control-condition','config'=>['logic'=>'and','conditions'=>[['left'=>1,'operator'=>'equals','right'=>1]]]]],
    'connections'=>[['sourceId'=>'trigger','sourcePort'=>'output','targetId'=>'action:acceptance']]];
$result['observer_inactive'] = !$observer->is_active;
$result['observer_definition_matches'] = $observer->definition == $expectedDefinition;
$paused = $state['recovery']['paused_workflow_ids'] ?? [];
$result['workflow_activation_restored'] = Illuminate\Support\Facades\DB::table('workflows')->whereIn('id',$paused)->where('is_active',true)->count() === count($paused);
$result['qa_task_completed'] = (bool)($client->requestV4('GET','/api/v4/tasks/'.$state['fixtures']['task_id'])['is_completed']??false);
$result['contact_fields_restored'] = true;
foreach ($state['recovery']['restore_fields']??[] as $path=>$fields) {
    $actual = $client->requestV4('GET','/api/v4/'.$path);
    foreach ($fields as $key=>$value) if (($actual[$key]??null) !== $value) $result['contact_fields_restored'] = false;
}
$hooks = $client->requestV4('GET','/api/v4/webhooks');
$result['webhooks'] = array_map(fn ($h) => ['id'=>$h['id'],'settings'=>$h['settings'],'disabled'=>$h['disabled']], $hooks['_embedded']['webhooks']??[]);
echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
