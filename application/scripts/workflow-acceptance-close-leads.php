<?php

declare(strict_types=1);

// Narrow standalone operation explicitly requested by the account owner: close existing deals.
$root=getenv('WORKFLOW_APP_ROOT') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$options=getopt('', ['workflow:','expected-domain:','report:','execute']);
if(!isset($options['execute'],$options['workflow'],$options['expected-domain'],$options['report'])) throw new RuntimeException('Explicit workflow/domain/report/execute required');
$workflow=App\Models\Workflows\Workflow::withoutGlobalScopes()->whereNull('deleted_at')->findOrFail((int)$options['workflow']);
$account=App\Models\User::findOrFail($workflow->user_id)->resolveAmoAccountForWidget('workflows');
if(!$account||$account->subdomain!==$options['expected-domain']||(int)$account->user_id!==(int)$workflow->user_id) throw new RuntimeException('Account mismatch');
$client=new App\Services\amoCRM\Client($account);
$info=$client->requestV4('GET','/api/v4/account');
if(!($info['is_technical_account']??false)) throw new RuntimeException('Technical account required');
$host=$account->subdomain.'.amocrm.'.($account->zone?:'ru');
Illuminate\Support\Facades\Http::globalRequestMiddleware(function($request)use($host){
    if($request->getUri()->getHost()!==$host) throw new RuntimeException('Unexpected host');
    if($request->getMethod()==='GET') return $request;
    $body=json_decode((string)$request->getBody(),true);
    if($request->getMethod()!=='PATCH'||!preg_match('#^/api/v4/leads/[1-9][0-9]*$#',$request->getUri()->getPath())||$body!==['status_id'=>143]) throw new RuntimeException('Only closing an existing lead in 143 is allowed');
    usleep(250000);
    return $request;
});
$before=$client->requestV4('GET','/api/v4/leads',[],['limit'=>250]);
if(!empty($before['_links']['next'])) throw new RuntimeException('Account exceeds expected small test scope');
$result=['scope'=>'close_existing_leads_only','workflow_id'=>$workflow->id,'subdomain'=>$account->subdomain,'closed'=>[],'already_closed'=>[],'failures'=>[]];
foreach($before['_embedded']['leads']??[] as $lead) {
    if(in_array((int)$lead['status_id'],[142,143],true)) { $result['already_closed'][]=$lead['id']; continue; }
    try {
        $client->requestV4('PATCH','/api/v4/leads/'.$lead['id'],['status_id'=>143]);
        $check=$client->requestV4('GET','/api/v4/leads/'.$lead['id']);
        if((int)($check['status_id']??0)!==143) throw new RuntimeException('Closure readback failed');
        $result['closed'][]=['id'=>$lead['id'],'previous_status_id'=>$lead['status_id'],'status_id'=>143];
    } catch(Throwable $error) { $result['failures'][]=['id'=>$lead['id'],'error'=>$error->getMessage()]; }
}
$after=$client->requestV4('GET','/api/v4/leads',[],['limit'=>250]);
$result['open_leads_remaining']=count(array_filter($after['_embedded']['leads']??[],fn($lead)=>!in_array((int)$lead['status_id'],[142,143],true)));
$result['verified_at']=now()->toIso8601String();
file_put_contents($options['report'],json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
chmod($options['report'],0600);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['failures']!==[]||$result['open_leads_remaining']>0?1:0);
