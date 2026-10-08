<?php

declare(strict_types=1);

require (getenv('WORKFLOW_APP_ROOT') ?: dirname(__DIR__)).'/vendor/autoload.php';
$app = require (getenv('WORKFLOW_APP_ROOT') ?: dirname(__DIR__)).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$workflow = App\Models\Workflows\Workflow::withoutGlobalScopes()->findOrFail((int) ($argv[1] ?? 15));
$account = $workflow->account_id ? App\Models\Core\Account::find($workflow->account_id) : null;
$account ??= App\Models\User::findOrFail($workflow->user_id)->resolveAmoAccountForWidget('workflows');
if (!$account || (int) $account->user_id !== (int) $workflow->user_id) throw new RuntimeException('Account ownership mismatch');
$client = new App\Services\amoCRM\Client($account);
$result = [
    'workflow' => ['id' => $workflow->id, 'user_id' => $workflow->user_id, 'name' => $workflow->name, 'account_id' => $workflow->account_id, 'is_active' => $workflow->is_active],
    'connection' => ['id' => $account->id, 'subdomain' => $account->subdomain, 'zone' => $account->zone, 'amo_account_id' => $account->amo_account_id, 'active' => $account->active],
    'workflows' => App\Models\Workflows\Workflow::withoutGlobalScopes()->where('user_id', $workflow->user_id)->get()->map(fn ($w) => ['id'=>$w->id,'name'=>$w->name,'active'=>$w->is_active,'starts'=>array_map(fn($s)=>$s['type']??null, App\Services\Workflows\WorkflowStartNodes::all($w->definition??[])), 'actions'=>array_column($w->definition['actions']??[], 'type')])->all(),
];
foreach (['account'=>'/api/v4/account', 'leads'=>'/api/v4/leads', 'contacts'=>'/api/v4/contacts', 'companies'=>'/api/v4/companies', 'pipelines'=>'/api/v4/leads/pipelines', 'webhooks'=>'/api/v4/webhooks', 'tasks'=>'/api/v4/tasks', 'users'=>'/api/v4/users'] as $key=>$path) {
    try {
        $body=$client->requestV4('GET', $path, [], ['limit'=>250]);
        if ($key === 'webhooks') $body = array_map(fn($h)=>['id'=>$h['id']??null,'destination_host'=>parse_url($h['destination']??'',PHP_URL_HOST),'settings'=>$h['settings']??[], 'disabled'=>$h['disabled']??null], $body['_embedded']['webhooks']??[]);
        if ($key === 'account') $body=array_intersect_key($body,array_flip(['id','name','subdomain','current_user_id','country','currency','is_technical_account']));
        if (in_array($key,['leads','contacts','companies','tasks','users'])) $body=array_map(fn($e)=>array_intersect_key($e,array_flip(['id','name','status_id','pipeline_id','is_completed','entity_id','entity_type','responsible_user_id','_embedded'])), $body['_embedded'][$key]??[]);
        $result[$key]=$body;
    } catch (Throwable $e) { $result[$key]=['error'=>$e->getMessage()]; }
}
$result['history'] = Illuminate\Support\Facades\DB::table('workflow_runs')->whereIn('workflow_id', array_column($result['workflows'],'id'))->select('workflow_id','status')->selectRaw('count(*) as total')->groupBy('workflow_id','status')->get()->all();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
