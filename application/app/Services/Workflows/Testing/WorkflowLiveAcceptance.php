<?php

declare(strict_types=1);

namespace App\Services\Workflows\Testing;

use App\Models\Core\Account;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use App\Services\Workflows\WorkflowAmoCrmWebhookPayloadNormalizer;
use App\Services\Workflows\WorkflowAmoReadCatalog;
use App\Services\Workflows\WorkflowGenericWebhookService;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowDebugger;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Opt-in acceptance suite for one explicitly selected technical amoCRM account. */
final class WorkflowLiveAcceptance
{
    private Account $account;
    private Client $client;
    private Workflow $source;
    private ?Workflow $observer = null;
    private array $paused = [];
    private array $originalContacts = [];
    private array $originalLeads = [];
    private array $restore = [];
    private array $tasks = [];
    private ?string $hookUrl = null;
    private ?WorkflowAcceptanceWebhookSubscription $qaWebhookSubscription = null;
    private bool $qaWebhookCreationAttempted = false;
    private array $report = [];
    private string $reportPath;
    private string $marker;
    private int $leadId = 0;
    private int $contactId = 0;
    private int $pipelineId = 0;
    private ?string $recurringStatePath = null;
    private array $recurringState = [];
    private bool $mutationsPrepared = false;
    private bool $workflowsPaused = false;
    private int $observerAfterRunId = 0;
    private array $pendingCreates = [];
    private bool $recurringStateStarted = false;
    private bool $qaLinkMayExist = false;
    private bool $cancellationRequested = false;
    private bool $customersDisabledVerified = false;

    /** Reuses a single closed QA deal, task and observer; never grows CRM fixtures daily. */
    public function runRecurring(int $workflowId, string $expectedDomain, string $reportPath, string $statePath, ?int $expectedAmoAccountId = null): array
    {
        if ($statePath === $reportPath) throw new RuntimeException('Recurring state and report paths must differ');
        $this->recurringStatePath = $statePath;
        return $this->run($workflowId, $expectedDomain, $reportPath, $expectedAmoAccountId);
    }

    /** Safe fallback: real read nodes and existing execution history, no CRM mutations. */
    public function readOnly(int $workflowId,string $expectedDomain,string $reportPath): array
    {
        $this->reportPath=$reportPath;
        $this->marker='Clever QA read-only '.gmdate('Ymd-His');
        $this->source=Workflow::withoutGlobalScopes()->whereNull('deleted_at')->findOrFail($workflowId);
        $this->account=User::findOrFail($this->source->user_id)->resolveAmoAccountForWidget('workflows') ?? throw new RuntimeException('No account');
        if($this->account->subdomain!==$expectedDomain || (int)$this->account->user_id!==(int)$this->source->user_id) throw new RuntimeException('Account mismatch');
        Auth::loginUsingId($this->source->user_id);
        $this->client=new Client($this->account);
        $host=$expectedDomain.'.amocrm.'.($this->account->zone?:'ru');
        Http::globalRequestMiddleware(function($request)use($host){
            if($request->getUri()->getHost()!==$host||!in_array($request->getMethod(),['GET','HEAD'],true)) throw new RuntimeException('Read-only acceptance: request blocked');
            usleep(220000);
            return $request;
        });
        $this->report=['schema_version'=>1,'suite'=>'read_only_acceptance','run_id'=>$this->marker,'started_at'=>now()->toIso8601String(),
            'source_workflow_id'=>$workflowId,'account'=>['platform_account_id'=>$this->account->id,'subdomain'=>$expectedDomain],
            'constraints'=>['crm_writes'=>false,'workflow_changes'=>false],'cases'=>[],'events'=>[],'historical_runs'=>[],'cleanup'=>[]];
        $this->exportHistory();
        $this->originalLeads=$this->list('leads');
        $this->originalContacts=$this->list('contacts');
        $companies=$this->list('companies');
        $this->report['before']=['leads'=>$this->originalLeads,'contact_ids'=>array_column($this->originalContacts,'id'),'company_ids'=>array_column($companies,'id')];
        $this->leadId=(int)($this->originalLeads[0]['id']??0);
        $this->contactId=(int)($this->originalContacts[0]['id']??0);
        $this->tasks=array_column($this->list('tasks'),'id');
        $this->node('query_leads','amocrm_query_leads',['limit'=>10],fn($o)=>['passed'=>is_array($o['items']??null)]);
        if($this->contactId) {
            $this->node('get_contact','amocrm_get_contact',['entity_source'=>'manual','target_entity'=>'contact','target_entity_id'=>$this->contactId],fn($o)=>['passed'=>(int)($o['id']??0)===$this->contactId]);
            $this->node('contact_leads','amocrm_contact_leads',['source'=>'contact','contact_id'=>$this->contactId],fn($o)=>['passed'=>isset($o['items'])&&is_array($o['items'])]);
        }
        $this->exerciseReads();
        $this->report['finished_at']=now()->toIso8601String();
        $this->report['counts']=array_count_values(array_column($this->report['cases'],'status'));
        $this->save();
        return $this->report;
    }

    public function run(int $workflowId, string $expectedDomain, string $reportPath, ?int $expectedAmoAccountId = null): array
    {
        $this->reportPath = $reportPath;
        $this->marker = 'Clever QA '.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        $this->source = Workflow::withoutGlobalScopes()->whereNull('deleted_at')->findOrFail($workflowId);
        $this->account = User::findOrFail($this->source->user_id)->resolveAmoAccountForWidget('workflows')
            ?? throw new RuntimeException('No connected account');
        if ($this->account->subdomain !== $expectedDomain || !$this->account->active
            || (int)$this->account->user_id !== (int)$this->source->user_id) {
            throw new RuntimeException('Explicit account scope does not match');
        }
        Auth::loginUsingId($this->source->user_id);
        $this->client = new Client($this->account);
        $remote = $this->client->requestV4('GET', '/api/v4/account');
        if (!($remote['is_technical_account'] ?? false)) throw new RuntimeException('This runner only accepts a technical amoCRM account');
        if ($expectedAmoAccountId !== null && (int)($remote['id'] ?? 0) !== $expectedAmoAccountId) throw new RuntimeException('Technical amoCRM account ID does not match the configured account');
        $lock = Cache::lock('workflow-acceptance:'.$this->account->id, 1800);
        if (!$lock->get()) throw new RuntimeException('An acceptance run for this account is already active');
        $this->report = ['schema_version'=>1, 'suite'=>$this->recurringStatePath ? 'recurring_live_acceptance' : 'live_acceptance', 'run_id'=>$this->marker,
            'started_at'=>now()->toIso8601String(), 'source_workflow_id'=>$workflowId,
            'account'=>['platform_account_id'=>$this->account->id, 'amo_account_id'=>$remote['id'], 'subdomain'=>$expectedDomain],
            'constraints'=>['max_open_leads'=>10, 'create_contacts'=>false, 'create_companies'=>false, 'final_lead_status'=>143],
            'cases'=>[], 'events'=>[], 'historical_runs'=>[], 'cleanup'=>[]];
        foreach (['WorkflowAmoCrmActionExecutor','WorkflowAmoCrmWebhookPayloadNormalizer','WorkflowAmoReadCatalog'] as $component) {
            $path=app_path('Services/Workflows/'.$component.'.php');
            $this->report['runtime_sha256'][$component]=hash_file('sha256',$path);
        }
        $signalHandlers = [];
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                $signalHandlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, function (int $signal): void {
                    $this->cancellationRequested=true;
                    throw new RuntimeException('Acceptance run cancelled by signal '.$signal);
                });
            }
        }
        try {
            if ($this->recurringStatePath) $this->beginRecurringState();
            $this->installRequestGuard();
            $this->originalLeads = $this->list('leads');
            $this->originalContacts = $this->list('contacts');
            $companies = $this->list('companies');
            $this->report['before'] = ['leads'=>$this->originalLeads, 'contact_ids'=>array_column($this->originalContacts,'id'), 'company_ids'=>array_column($companies,'id')];
            if ($this->recurringStatePath) {
                self::assertRecurringInventory($this->originalLeads, $this->originalContacts, $companies);
                if (empty($this->recurringState['fixtures']['lead_id'])) foreach ($this->originalLeads as $lead) {
                    if (str_starts_with((string)($lead['name']??''),'Clever QA recurring ')) throw new RuntimeException('An untracked recurring QA lead already exists; recover the checkpoint instead of creating more fixtures');
                }
            }
            $this->exportHistory();
            $pipelines = $this->client->requestV4('GET','/api/v4/leads/pipelines');
            $this->pipelineId = (int)($pipelines['_embedded']['pipelines'][0]['id'] ?? 0);
            if (!$this->pipelineId) throw new RuntimeException('No pipeline available');
            $this->contactId = (int)($this->originalContacts[0]['id'] ?? 0);
            $this->pauseWorkflows();
            $this->setupObserver();
            if ($this->cancellationRequested) throw new RuntimeException('Acceptance run cancelled before CRM mutations');
            $this->mutationsPrepared = true;
            // User explicitly requested closing all deals in this technical account.
            if ($this->recurringStatePath) $this->exerciseRecurringNodes();
            else { $this->closeAllLeads(); $this->exerciseNodes(); }
            $this->exerciseReads();
            $this->collectEvents(15);
        } catch (Throwable $e) {
            $this->report['fatal_error'] = $e->getMessage();
        } finally {
            // A second termination signal must not interrupt restoration in progress.
            foreach ($signalHandlers as $signal=>$handler) pcntl_signal($signal, SIG_IGN);
            try {
                $this->cleanup();
            } catch (Throwable $e) {
                $this->report['cleanup']['unexpected_failure'] = ['ok'=>false,'error'=>$e->getMessage()];
            } finally {
                try { $lock->release(); }
                catch (Throwable $e) { $this->report['cleanup']['release_lock'] = ['ok'=>false,'error'=>$e->getMessage()]; }
            }
            $this->report['finished_at'] = now()->toIso8601String();
            $this->report['counts'] = array_count_values(array_column($this->report['cases'],'status'));
            try { $this->save(); }
            catch (Throwable $e) {
                $this->report['report_write_errors'][] = $e->getMessage();
                fwrite(STDERR, 'Acceptance report could not be saved; cleanup results remain in the returned report.'.PHP_EOL);
            }
            if ($this->recurringStateStarted) {
                $safe = $this->pendingCreates === [] && empty($this->report['report_write_errors'])
                    && !array_filter($this->report['cleanup'], fn($item)=>!($item['ok']??false));
                $this->recurringState['phase'] = $safe ? 'ready' : 'recovery_required';
                try { $this->saveRecurringState(); }
                catch (Throwable $e) {
                    $this->report['fatal_error'] = 'Recurring recovery checkpoint could not be finalized: '.$e->getMessage();
                    try { $this->save(false); }
                    catch (Throwable $reportError) { $this->report['report_write_errors'][]=$reportError->getMessage(); }
                }
            }
            foreach ($signalHandlers as $signal=>$handler) pcntl_signal($signal, $handler);
        }
        return $this->report;
    }

    /** Defense in depth: no contact/company creation, no nested creation, no arbitrary destination. */
    private function installRequestGuard(): void
    {
        $host = $this->account->subdomain.'.amocrm.'.($this->account->zone ?: 'ru');
        Http::globalRequestMiddleware(function ($request) use ($host) {
            // Bound the suite below amoCRM's per-integration rate limit.
            usleep(220000);
            $path = $request->getUri()->getPath();
            $method = $request->getMethod();
            if ($request->getUri()->getHost() === 'app.clevercrm.pro' && $method === 'GET' && $path === '/up') return $request;
            if ($request->getUri()->getHost() !== $host) throw new RuntimeException('Acceptance guard: external destination denied');
            if (in_array($method,['GET','HEAD'],true)) return $request;
            $body = json_decode((string)$request->getBody(),true) ?? [];
            self::assertSafeMutation($method, $path, $body);
            if ($this->recurringStatePath) self::assertRecurringMutation($method, $path, $body, [
                'lead_id'=>$this->leadId, 'contact_id'=>$this->contactId, 'task_ids'=>$this->tasks,
                'hook_url'=>$this->hookUrl, 'pending_creates'=>$this->pendingCreates,
            ]);
            return $request;
        });
    }

    public static function assertRecurringInventory(array $leads, array $contacts, array $companies): void
    {
        if (count($leads)>10 || count($contacts)>10 || count($companies)>10) throw new RuntimeException('Recurring acceptance account exceeds its ten-entity cap');
        foreach ($leads as $lead) if (!in_array((int)($lead['status_id']??0), [142,143], true)) {
            throw new RuntimeException('Recurring acceptance requires all existing leads to be closed; it will not change non-QA deals');
        }
    }

    public static function assertRecurringCheckpoint(array $state, int $workflowId, string $domain, int $accountId): void
    {
        if (($state['schema_version']??0)!==1 || ($state['phase']??'')!=='ready'
            || (int)($state['source_workflow_id']??0)!==$workflowId || ($state['domain']??'')!==$domain
            || (int)($state['amo_account_id']??0)!==$accountId) {
            throw new RuntimeException('Recurring acceptance checkpoint requires manual recovery or belongs to another account; no mutations were started');
        }
    }

    /** Additional per-run IDs restrict the broader one-shot allowlist. */
    public static function assertRecurringMutation(string $method, string $path, array $body, array $scope): void
    {
        $leadId=(int)($scope['lead_id']??0); $contactId=(int)($scope['contact_id']??0);
        if ($path==='/api/v4/webhooks' && in_array($method,['POST','DELETE'],true)
            && !empty($scope['hook_url']) && ($body['destination']??null)===$scope['hook_url']) return;
        if ($method==='POST' && $path==='/api/v4/leads' && in_array('lead',$scope['pending_creates']??[],true)) {
            self::assertSafeMutation($method,$path,$body);
            if (count($body)===1 && str_starts_with((string)($body[0]['name']??''),'Clever QA recurring ')) return;
        }
        if ($method==='POST' && $path==='/api/v4/tasks' && in_array('task',$scope['pending_creates']??[],true)
            && count($body)===1 && (int)($body[0]['entity_id']??0)===$leadId && $leadId>0
            && ($body[0]['entity_type']??'')==='leads' && str_starts_with((string)($body[0]['text']??''),'Clever QA recurring ')) return;
        if ($method==='PATCH' && $leadId>0 && $path==='/api/v4/leads/'.$leadId
            && (!isset($body['status_id']) || in_array((int)$body['status_id'],[142,143],true))) return;
        if ($method==='PATCH' && $contactId>0 && $path==='/api/v4/contacts/'.$contactId
            && array_keys($body)===['name'] && is_string($body['name'])) return;
        if ($method==='PATCH' && preg_match('#^/api/v4/tasks/([1-9][0-9]*)$#',$path,$match)
            && in_array((int)$match[1],array_map('intval',$scope['task_ids']??[]),true)
            && array_diff(array_keys($body),['text','is_completed','result','complete_till'])===[]) return;
        if ($method==='POST' && $leadId>0 && $contactId>0
            && in_array($path,['/api/v4/leads/'.$leadId.'/link','/api/v4/leads/'.$leadId.'/unlink'],true)
            && count($body)===1 && (int)($body[0]['to_entity_id']??0)===$contactId && ($body[0]['to_entity_type']??'')==='contacts') return;
        throw new RuntimeException('Recurring acceptance guard: mutation is not scoped to this run\'s reusable QA fixtures');
    }

    private function beginRecurringState(): void
    {
        $state = is_file($this->recurringStatePath)
            ? json_decode((string)file_get_contents($this->recurringStatePath),true,flags:JSON_THROW_ON_ERROR)
            : ['schema_version'=>1,'phase'=>'ready','source_workflow_id'=>$this->source->id,
                'domain'=>$this->account->subdomain,'amo_account_id'=>$this->report['account']['amo_account_id'],'fixtures'=>[]];
        if (!is_array($state)) throw new RuntimeException('Invalid recurring acceptance checkpoint');
        self::assertRecurringCheckpoint($state,(int)$this->source->id,$this->account->subdomain,(int)$this->report['account']['amo_account_id']);
        $this->recurringState=$state;
        $this->recurringState['phase']='running';
        $this->saveRecurringState();
        $this->recurringStateStarted=true;
    }

    private function saveRecurringState(): void
    {
        $this->recurringState['last_run_id']=$this->marker;
        $this->recurringState['report_path']=$this->reportPath;
        $this->recurringState['recovery']=['restore_fields'=>$this->restore,'task_ids'=>$this->tasks,
            'paused_workflow_ids'=>$this->paused,'observer_workflow_id'=>$this->observer?->id,
            'pending_creates'=>$this->pendingCreates,'lead_id'=>$this->leadId,'qa_link_may_exist'=>$this->qaLinkMayExist,
            'qa_webhook_creation_attempted'=>$this->qaWebhookCreationAttempted];
        $this->writePrivateJson($this->recurringStatePath,$this->recurringState);
    }

    public static function assertSafeMutation(string $method, string $path, array $body): void
    {
        if ($method === 'POST' && preg_match('#^/api/v4/(contacts|companies|customers)(?:/|$)#', $path)
            && !preg_match('#^/api/v4/(contacts|companies)/[1-9][0-9]*/(notes|link|unlink)$#', $path)) {
            throw new RuntimeException('Acceptance guard: creating contacts/companies/customers is forbidden');
        }
        $walk = function (array $data) use (&$walk): void {
            foreach ($data as $key=>$value) {
                if ($key === '_embedded' && is_array($value)) {
                    foreach (['contacts','companies','customers'] as $entity) {
                        $records=$value[$entity]??[];
                        if (!is_array($records)||!array_is_list($records)) throw new RuntimeException('Acceptance guard: embedded references must be a list');
                        foreach ($records as $record) {
                            if (!is_array($record) || !is_numeric($record['id']??null) || (int)$record['id']<=0) throw new RuntimeException('Acceptance guard: embedded entity creation is forbidden');
                        }
                    }
                }
                if (is_array($value)) $walk($value);
            }
        };
        $walk($body);
        if ($path === '/api/v4/webhooks' && in_array($method,['POST','DELETE'],true)) return;
        if ($method === 'POST' && $path === '/api/v4/leads') {
            if (!array_is_list($body) || count($body)!==1 || !in_array((int)($body[0]['status_id']??0),[142,143],true)) {
                throw new RuntimeException('Acceptance guard: create only one already closed lead per request');
            }
            return;
        }
        if ($method === 'POST' && $path === '/api/v4/tasks') return;
        if ($method === 'POST' && preg_match('#^/api/v4/(leads|contacts|companies)/[1-9][0-9]*/(notes|link|unlink)$#',$path)) return;
        if ($method === 'PATCH' && preg_match('#^/api/v4/(leads|contacts|companies|tasks)/[1-9][0-9]*$#',$path)) return;
        throw new RuntimeException('Acceptance guard: mutation is outside the suite allowlist');
    }

    private function pauseWorkflows(): void
    {
        $ids = Workflow::withoutGlobalScopes()->whereNull('deleted_at')->where('user_id',$this->source->user_id)->pluck('id');
        if (DB::table('workflow_runs')->whereIn('workflow_id',$ids)->whereIn('status',['pending','running'])->exists()) {
            throw new RuntimeException('The account has pending/running workflows; wait for them before acceptance tests');
        }
        $this->paused = DB::table('workflows')->whereIn('id',$ids)->where('is_active',true)->pluck('id')->all();
        if ($this->recurringStatePath && in_array((int)($this->recurringState['fixtures']['observer_workflow_id']??0),array_map('intval',$this->paused),true)) {
            $this->paused=[];
            throw new RuntimeException('Reusable QA observer is already active; manual recovery required');
        }
        $this->report['paused_workflow_ids'] = $this->paused;
        $this->save();
        $this->workflowsPaused = true;
        DB::table('workflows')->whereIn('id',$this->paused)->where('user_id',$this->source->user_id)->update(['is_active'=>false]);
        if (DB::table('workflow_runs')->whereIn('workflow_id',$ids)->whereIn('status',['pending','running'])->exists()) {
            throw new RuntimeException('A workflow started during acceptance preparation; no CRM mutations will be performed');
        }
    }

    private function setupObserver(): void
    {
        $definition=['trigger'=>['type'=>'generic-webhook','config'=>['source'=>'webhook']],
                'actions'=>[['id'=>'acceptance','type'=>'control-condition','config'=>['logic'=>'and','conditions'=>[['left'=>1,'operator'=>'equals','right'=>1]]]]],
                'connections'=>[['sourceId'=>'trigger','sourcePort'=>'output','targetId'=>'action:acceptance']]];
        $observerId=(int)($this->recurringState['fixtures']['observer_workflow_id']??0);
        if ($observerId) {
            $this->observer=Workflow::withoutGlobalScopes()->whereNull('deleted_at')->findOrFail($observerId);
            if ((int)$this->observer->user_id!==(int)$this->source->user_id || $this->observer->is_active || $this->observer->definition!=$definition) {
                $this->observer=null;
                throw new RuntimeException('Reusable QA observer was modified or is active; manual inspection required');
            }
        } else {
            if ($this->recurringStatePath && Workflow::withoutGlobalScopes()->where('user_id',$this->source->user_id)->where('name','Clever QA recurring · capture')->exists()) {
                throw new RuntimeException('An untracked recurring QA observer already exists; recover its checkpoint before creating another');
            }
            $this->observer = new Workflow;
            $this->observer->forceFill(['name'=>($this->recurringStatePath?'Clever QA recurring':$this->marker).' · capture', 'user_id'=>$this->source->user_id,
                'is_active'=>false, 'max_retries'=>0, 'failure_strategy'=>'stop', 'definition'=>$definition]);
            $this->observer->save();
            if ($this->recurringStatePath) $this->recurringState['fixtures']['observer_workflow_id']=$this->observer->id;
        }
        $this->observerAfterRunId=(int)DB::table('workflow_runs')->where('workflow_id',$this->observer->id)->max('id');
        $this->save();
        $this->observer->forceFill(['is_active'=>true])->save();
        if (!$this->observer->is_active) throw new RuntimeException('Observer could not be activated');
        $this->report['observer_workflow_id'] = $this->observer->id;
        $this->hookUrl = app(WorkflowGenericWebhookService::class)->callbackUrl($this->observer);
        $this->save();
        $events=AmoCrmWebhookTriggerCatalog::eventCodes();
        // Set this before POST: a timeout does not prove that creation never happened.
        $this->qaWebhookCreationAttempted=true;
        $this->report['webhook_subscription']=['status'=>'creating','events'=>$events,'trace'=>[]];
        $verification=$this->webhookSubscription()->install($this->hookUrl,$events);
        $this->report['webhook_subscription']=array_replace($this->report['webhook_subscription'],[
            'status'=>'installed','verification'=>$verification,
        ]);
        $this->save();
    }

    private function webhookSubscription(): WorkflowAcceptanceWebhookSubscription
    {
        return $this->qaWebhookSubscription ??= new WorkflowAcceptanceWebhookSubscription(
            fn(string $method,string $path,array $body=[],array $query=[]): array => $this->client->requestV4($method,$path,$body,$query),
            function(array $event): void {
                $this->report['webhook_subscription']['trace'][]=$event;
                try { $this->save(); }
                catch(Throwable) {
                    // Keep evidence in memory and fail the checkpoint closed, but never
                    // let a full disk prevent DELETE or the rest of account recovery.
                    $this->report['report_write_errors'][]='Не удалось сохранить диагностику тестового вебхука; восстановление аккаунта продолжается.';
                }
            },
        );
    }

    private function exerciseRecurringNodes(): void
    {
        $this->leadId=(int)($this->recurringState['fixtures']['lead_id']??0);
        if (!$this->leadId) {
            if (count($this->list('leads'))>=10) throw new RuntimeException('Cannot create the reusable QA lead: account already has ten leads');
            $this->pendingCreates[]='lead'; $this->save();
            $create=$this->node('create_lead','amocrm_create_lead',$this->qaLeadCreateConfig('Clever QA recurring fixture'),
                fn($o)=>$this->verifyEntity('leads',(int)($o['entity_id']??0),['name'=>'Clever QA recurring fixture','status_id'=>143]));
            $this->leadId=(int)($create['output']['entity_id']??0);
            if (!$this->leadId) throw new RuntimeException('QA lead creation returned no ID; recovery required before another attempt');
            $this->recurringState['fixtures']['lead_id']=$this->leadId;
            $this->pendingCreates=array_values(array_diff($this->pendingCreates,['lead'])); $this->save();
        } else {
            $lead=$this->get('leads',$this->leadId);
            if (!str_starts_with((string)($lead['name']??''),'Clever QA recurring ') || !in_array((int)($lead['status_id']??0),[142,143],true)) {
                $this->leadId=0;
                throw new RuntimeException('Reusable QA deal was modified outside the suite; refusing to mutate it');
            }
            $this->skip('create_lead','amocrm_create_lead','QA fixture already exists; repeated creation would consume account capacity');
        }
        $this->pipelineId=(int)($this->get('leads',$this->leadId)['pipeline_id']??$this->pipelineId);
        $target=['entity_source'=>'manual','target_entity'=>'lead','target_entity_id'=>$this->leadId];
        $name='Clever QA recurring '.gmdate('Ymd-His').'-'.bin2hex(random_bytes(2));
        $this->node('update_lead','amocrm_update_lead_fields',$target+['body_mode'=>'json','json_body'=>['name'=>$name,'price'=>123]],
            fn()=> $this->verifyEntity('leads',$this->leadId,['name'=>$name,'price'=>123]));
        $this->node('query_leads','amocrm_query_leads',['filters'=>[['field'=>'id','operator'=>'eq','value'=>$this->leadId]],'limit'=>10],
            fn($o)=>['passed'=>in_array($this->leadId,array_map('intval',array_column($o['items']??[],'id')),true),'expected_id'=>$this->leadId]);
        $this->node('find_lead','amocrm_find_entity',['target_entity'=>'lead','conditions'=>[['field'=>'system:name','operator'=>'equals','value'=>$name]]],
            fn($o)=>['passed'=>($o['found']??false)===true && (int)($o['entity_id']??0)===$this->leadId]);
        foreach ([142,143] as $status) $this->node('lead_status_'.$status,'amocrm_change_lead_status',$target+['pipeline_id'=>$this->pipelineId,'status_id'=>$status],
            fn()=> $this->verifyEntity('leads',$this->leadId,['status_id'=>$status]));
        $this->node('lead_tags','amocrm_change_tags',$target+['tags_to_add'=>'Clever QA'],
            fn()=>['passed'=>in_array('Clever QA',array_column($this->get('leads',$this->leadId)['_embedded']['tags']??[],'name'),true)]);
        $this->node('remove_lead_tags','amocrm_change_tags',$target+['tags_to_remove'=>'Clever QA'],
            function():array {
                $tags=$this->get('leads',$this->leadId)['_embedded']['tags']??[];
                $passed=!in_array('Clever QA',array_column($tags,'name'),true);
                return ['passed'=>$passed,'expected'=>'Tag Clever QA is absent','readback_tags'=>$tags,
                    'reason'=>$passed?null:'После удаления тег Clever QA остался на QA-сделке'];
            });
        if ($this->contactId) {
            if ($this->linkCheck(true)['passed']) throw new RuntimeException('QA lead is already linked to the selected contact; refusing to remove a preexisting link');
            $contact=$this->get('contacts',$this->contactId);
            $this->restore['contacts/'.$this->contactId]=['name'=>$contact['name']]; $this->save();
            $cTarget=['entity_source'=>'manual','target_entity'=>'contact','target_entity_id'=>$this->contactId];
            $this->node('get_contact','amocrm_get_contact',$cTarget,fn($o)=>['passed'=>(int)($o['id']??0)===$this->contactId]);
            $this->node('update_contact','amocrm_update_contact_fields',$cTarget+['body_mode'=>'json','json_body'=>['name'=>$name.' contact']],
                fn()=> $this->verifyEntity('contacts',$this->contactId,['name'=>$name.' contact']));
            $link=$target+['linked_entity'=>'contact','linked_entity_id'=>$this->contactId];
            $this->qaLinkMayExist=true; $this->save();
            $this->node('link_contact','amocrm_link_entity',$link,fn()=> $this->linkCheck(true));
            $this->node('contact_leads','amocrm_contact_leads',['source'=>'contact','contact_id'=>$this->contactId],
                fn($o)=>['passed'=>in_array($this->leadId,array_map('intval',array_column($o['items']??[],'id')),true)]);
            $this->node('unlink_contact','amocrm_unlink_entity',$link,fn()=> $this->linkCheck(false));
            $this->client->requestV4('PATCH','/api/v4/contacts/'.$this->contactId,$this->restore['contacts/'.$this->contactId]);
        } else $this->skip('update_contact','amocrm_update_contact_fields','Account has no existing contact; creation is forbidden');
        $taskId=(int)($this->recurringState['fixtures']['task_id']??0);
        if (!$taskId) {
            $this->pendingCreates[]='task'; $this->save();
            $task=$this->node('create_task','amocrm_create_task',$target+['text'=>'Clever QA recurring task','task_type_id'=>1,'complete_till'=>time()+86400],
                fn($o)=>$this->verifyEntity('tasks',(int)($o['entity_id']??0),['entity_id'=>$this->leadId]));
            $taskId=(int)($task['output']['entity_id']??0);
            if (!$taskId) throw new RuntimeException('QA task creation returned no ID; recovery required before another attempt');
            $this->tasks=[$taskId];
            $this->recurringState['fixtures']['task_id']=$taskId;
            $this->pendingCreates=array_values(array_diff($this->pendingCreates,['task']));
        } else {
            $task=$this->get('tasks',$taskId);
            if ((int)($task['entity_id']??0)!==$this->leadId || ($task['entity_type']??'')!=='leads' || !str_starts_with((string)($task['text']??''),'Clever QA recurring ')) {
                throw new RuntimeException('Reusable QA task no longer belongs to the isolated QA lead');
            }
            $this->skip('create_task','amocrm_create_task','Reusable QA task exists; recurring runs test update/completion without adding tasks');
        }
        $this->tasks=[$taskId]; $this->save();
        $this->apiCase('task_update_event','PATCH','/api/v4/tasks/'.$taskId,['text'=>$name.' task','is_completed'=>false,'complete_till'=>time()+86400]);
        $this->apiCase('task_completed_event','PATCH','/api/v4/tasks/'.$taskId,['is_completed'=>true,'result'=>['text'=>'QA completed']]);
        $this->skip('copy_lead','amocrm_copy_lead','Recurring suite does not create another lead each day');
        $this->skip('lead_note','amocrm_add_note','Notes are append-only; recurring suite avoids adding daily notes to CRM');
        $this->skip('contact_note','amocrm_add_note','Recurring suite does not append notes to existing contacts');
        $this->node('http_request','http_request',['url'=>'https://app.clevercrm.pro/up','method'=>'GET','timeout'=>10,'headers'=>'{}'],fn($o)=>['passed'=>($o['status']??0)===200]);
        $this->node('missing_note_text','amocrm_add_note',$target+['text'=>''],null,true);
        $this->node('invalid_salesbot_config','amocrm_start_salesbot',['bot_id'=>0],null,true);
        $this->node('invalid_contact_id','amocrm_get_contact',['entity_source'=>'manual','target_entity'=>'contact','target_entity_id'=>0],null,true);
        $this->node('invalid_update_json','amocrm_update_lead_fields',$target+['body_mode'=>'json','json_body'=>'{invalid'],null,true);
    }

    private function skip(string $id,string $type,string $reason): void
    {
        $this->report['cases'][]=compact('id','type','reason')+['status'=>'skipped']; $this->save();
    }

    private function qaLeadCreateConfig(string $name): array
    {
        // The initial context already contains an existing contact but no QA lead ID.
        // Lock the creation target to leads so the executor cannot implicitly link it
        // to that existing contact before the suite has recorded the new lead ID.
        return ['name'=>$name,'pipeline_id'=>$this->pipelineId,'status_id'=>143,'price'=>0,
            'target_entity'=>'lead','target_entity_locked'=>true];
    }

    private function exerciseNodes(): void
    {
        $create = $this->node('create_lead','amocrm_create_lead',$this->qaLeadCreateConfig($this->marker),
            fn($o)=>$this->verifyEntity('leads',(int)($o['entity_id']??0), ['name'=>$this->marker,'status_id'=>143]));
        $this->leadId = (int)($create['output']['entity_id']??0);
        if (!$this->leadId) throw new RuntimeException('Cannot continue: isolated QA deal was not created');
        $target = ['entity_source'=>'manual','target_entity'=>'lead','target_entity_id'=>$this->leadId];
        $this->node('update_lead','amocrm_update_lead_fields',$target+['body_mode'=>'json','json_body'=>['name'=>$this->marker.' updated','price'=>123]],
            fn()=> $this->verifyEntity('leads',$this->leadId,['name'=>$this->marker.' updated','price'=>123]));
        $this->node('query_leads','amocrm_query_leads',['filters'=>[['field'=>'id','operator'=>'eq','value'=>$this->leadId]],'limit'=>10],
            fn($o)=>['passed'=>in_array($this->leadId,array_column($o['items']??[],'id'),true),'expected_id'=>$this->leadId]);
        $this->node('find_lead','amocrm_find_entity',['target_entity'=>'lead','conditions'=>[['field'=>'system:name','operator'=>'equals','value'=>$this->marker.' updated']]],
            fn($o)=>['passed'=>($o['found']??false)===true && (int)($o['entity_id']??0)===$this->leadId,'expected_id'=>$this->leadId]);
        $this->node('lead_note','amocrm_add_note',$target+['text'=>$this->marker.' note'],
            fn($o)=>$this->verifyEntity('leads/'.$this->leadId.'/notes',(int)($o['entity_id']??0),['params.text'=>$this->marker.' note']));
        $this->node('lead_tags','amocrm_change_tags',$target+['tags_to_add'=>'Clever QA'],
            fn()=>['passed'=>in_array('Clever QA',array_column($this->get('leads',$this->leadId)['_embedded']['tags']??[],'name'),true)]);
        foreach ([142,143] as $status) {
            $this->node('lead_status_'.$status,'amocrm_change_lead_status',$target+['pipeline_id'=>$this->pipelineId,'status_id'=>$status],
                fn()=> $this->verifyEntity('leads',$this->leadId,['status_id'=>$status]));
        }
        $copy = $this->node('copy_lead','amocrm_copy_lead',$target+['name'=>$this->marker.' copy','status_id'=>143],
            fn($o)=>(int)($o['entity_id']??0)===$this->leadId
                ? ['passed'=>false,'reason'=>'Copy returned the original deal ID']
                : $this->verifyEntity('leads',(int)($o['entity_id']??0),['name'=>$this->marker.' copy','status_id'=>143]));
        if ($id=(int)($copy['output']['entity_id']??0)) $this->client->requestV4('PATCH','/api/v4/leads/'.$id,['status_id'=>143]);
        if ($this->contactId) {
            $contact=$this->get('contacts',$this->contactId);
            $this->restore['contacts/'.$this->contactId]=['name'=>$contact['name']];
            $this->save();
            $cTarget=['entity_source'=>'manual','target_entity'=>'contact','target_entity_id'=>$this->contactId];
            $this->node('get_contact','amocrm_get_contact',$cTarget,fn($o)=>['passed'=>(int)($o['id']??0)===$this->contactId && (int)($o['contact']['id']??0)===$this->contactId,'expected_id'=>$this->contactId]);
            $this->node('update_contact','amocrm_update_contact_fields',$cTarget+['body_mode'=>'json','json_body'=>['name'=>$this->marker.' existing contact']],
                fn()=> $this->verifyEntity('contacts',$this->contactId,['name'=>$this->marker.' existing contact']));
            $this->node('contact_note','amocrm_add_note',$cTarget+['text'=>$this->marker.' contact note'],
                fn($o)=>$this->verifyEntity('contacts/'.$this->contactId.'/notes',(int)($o['entity_id']??0),['params.text'=>$this->marker.' contact note']));
            $link=$target+['linked_entity'=>'contact','linked_entity_id'=>$this->contactId];
            $this->node('link_contact','amocrm_link_entity',$link,fn()=> $this->linkCheck(true));
            $this->node('contact_leads','amocrm_contact_leads',['source'=>'contact','contact_id'=>$this->contactId],
                fn($o)=>['passed'=>(int)($o['contact_id']??0)===$this->contactId && in_array($this->leadId,array_column($o['items']??[],'id'),true),'expected_lead_id'=>$this->leadId]);
            $this->node('unlink_contact','amocrm_unlink_entity',$link,fn()=> $this->linkCheck(false));
            $this->client->requestV4('PATCH','/api/v4/contacts/'.$this->contactId,$this->restore['contacts/'.$this->contactId]);
        }
        $task=$this->node('create_task','amocrm_create_task',$target+['text'=>$this->marker.' task','task_type_id'=>1,'complete_till'=>time()+86400],
            fn($o)=>$this->verifyEntity('tasks',(int)($o['entity_id']??0),['text'=>$this->marker.' task','entity_id'=>$this->leadId]));
        if ($id=(int)($task['output']['entity_id']??0)) {
            $this->tasks[]=$id; $this->save();
            $this->apiCase('task_update_event','PATCH','/api/v4/tasks/'.$id,['text'=>$this->marker.' task updated']);
            $this->apiCase('task_completed_event','PATCH','/api/v4/tasks/'.$id,['is_completed'=>true,'result'=>['text'=>$this->marker.' completed']]);
        }
        $this->node('filter_real_leads','workflow_filter_list',['items'=>[$this->get('leads',$this->leadId)],'match'=>'all','rules'=>[['field'=>'status_id','operator'=>'eq','value'=>143]]],fn($o)=>['passed'=>($o['count']??0)===1]);
        $this->node('filter_no_matches','workflow_filter_list',['items'=>[$this->get('leads',$this->leadId)],'match'=>'all','rules'=>[['field'=>'status_id','operator'=>'eq','value'=>142]]],fn($o)=>['passed'=>($o['count']??null)===0 && ($o['items']??null)===[] && ($o['has_matches']??null)===false]);
        $this->node('condition_true','control-condition',['logic'=>'and','conditions'=>[['left'=>'{{ $node["trigger"].json.lead.id }}','operator'=>'equals','right'=>$this->leadId]]],fn($o)=>['passed'=>($o['passed']??null)===true]);
        $this->node('condition_false','control-condition',['logic'=>'and','conditions'=>[['left'=>'{{ $json.lead.id }}','operator'=>'equals','right'=>0]]],fn($o)=>['passed'=>($o['passed']??null)===false && ($o['branch']??null)==='false']);
        $this->node('javascript','workflow_javascript',['javascript_code'=>'return {lead_id: $json.lead.id, verified: true};'],fn($o)=>['passed'=>($o['lead_id']??0)===$this->leadId]);
        $this->node('delay','workflow_delay',['seconds'=>1],fn($o)=>['passed'=>($o['qa_run_id']??null)===$this->marker && (int)($o['lead']['id']??0)===$this->leadId]);
        $this->node('http_request','http_request',['url'=>'https://app.clevercrm.pro/up','method'=>'GET','timeout'=>10,'headers'=>'{}'],fn($o)=>['passed'=>($o['status']??0)===200]);
        // Real validation failures exercise what a user sees, without external side effects.
        $this->node('missing_note_text','amocrm_add_note',$target+['text'=>''],null,true);
        $this->node('invalid_salesbot_config','amocrm_start_salesbot',['bot_id'=>0],null,true);
        $this->node('invalid_contact_id','amocrm_get_contact',['entity_source'=>'manual','target_entity'=>'contact','target_entity_id'=>0],null,true);
        $this->node('invalid_update_json','amocrm_update_lead_fields',$target+['body_mode'=>'json','json_body'=>'{invalid'],null,true);
    }

    private function exerciseReads(): void
    {
        $lookupCache=[];
        $firstId=function(string $path) use (&$lookupCache): array {
            if (isset($lookupCache[$path])) return $lookupCache[$path];
            if (!preg_match('~^/api/v4/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D',$path)) {
                return ['status'=>'failed','error'=>'Acceptance prerequisite path is malformed','path'=>$path];
            }
            try {
                $body=$this->client->requestV4('GET',$path,[],['limit'=>10,'page'=>1]);
                $id=null;
                foreach ($body['_embedded']??[] as $rows) {
                    if (!is_array($rows) || !array_is_list($rows)) continue;
                    foreach ($rows as $row) {
                        if (is_array($row) && (is_int($row['id']??null) || is_string($row['id']??null))) {
                            $id=$row['id'];
                            break 2;
                        }
                    }
                }
                return $lookupCache[$path]=['status'=>$id===null?'empty':'available','id'=>$id,'path'=>$path];
            } catch (Throwable $error) {
                if ($this->cancellationRequested) throw $error;
                return $lookupCache[$path]=self::classifyReadPrerequisiteError($path,$error->getMessage(),$this->customersDisabledVerified);
            }
        };
        // A bare customer Error 426 is ambiguous. Only the explicit independent
        // segments response can establish that customers are disabled here.
        $capability=$firstId('/api/v4/customers/segments');
        $this->customersDisabledVerified=($capability['capability']??null)==='customers_disabled';
        if ($this->customersDisabledVerified) $this->report['capabilities']['customers']=$capability;
        $ids=['leads'=>$this->leadId,'contacts'=>$this->contactId,'companies'=>$this->report['before']['company_ids'][0]??0,
            'tasks'=>$this->tasks[0]??0,'pipeline_id'=>$this->pipelineId];
        foreach (self::visibleReadOperations() as $operation=>$definition) {
            if ($this->cancellationRequested) throw new RuntimeException('Acceptance run cancelled');
            try {
                $resolved=self::resolveReadPrerequisites($operation,$definition,$ids,$firstId);
                if ($resolved['status']!=='ready') {
                    $this->report['cases'][]=['id'=>'read:'.$operation,'type'=>'amocrm_read','operation'=>$operation]+$resolved;
                    $this->save();
                    continue;
                }
                $config=$resolved['config'];
                $this->node('read:'.$operation,'amocrm_read',$config,function($o) use ($config,$definition) {
                    $passed=is_array($o['data']??null) && is_array($o['items']??null)
                        && ($o['count']??null)===count($o['items']) && is_bool($o['has_more']??null) && !($o['dry_run']??false);
                    if (str_ends_with($definition['path'],'/{id}')) $passed=$passed && (string)($o['data']['id']??'')===(string)$config['id'];
                    return ['passed'=>$passed,'count'=>$o['count']??null,'expected_id'=>$config['id']??null];
                });
            } catch (Throwable $error) {
                if ($this->cancellationRequested) throw $error;
                $this->report['cases'][]=['id'=>'read:'.$operation,'type'=>'amocrm_read','operation'=>$operation,'status'=>'failed','reason'=>'Could not obtain an existing prerequisite identifier','prerequisite_error'=>$error->getMessage()];
                $this->save();
            }
        }
    }

    /** UI families may group several real endpoints; acceptance must still exercise every visible variant. */
    public static function visibleReadOperations(?array $visible=null,?array $definitions=null): array
    {
        $visible??=WorkflowAmoReadCatalog::availableOperations();
        $definitions??=WorkflowAmoReadCatalog::operations();
        $expanded=[];
        foreach ($visible as $family=>$item) {
            $keys=is_array($item['variants']??null) && $item['variants']!==[] ? array_keys($item['variants']) : [$family];
            foreach ($keys as $key) {
                if (!isset($definitions[$key])) throw new RuntimeException('Visible amoCRM read variant is missing its endpoint definition: '.$key);
                $expanded[$key]=$definitions[$key];
            }
        }
        return $expanded;
    }

    /** Resolve parent identifiers before issuing child lookups; list endpoints need no arbitrary entity. */
    public static function resolveReadPrerequisites(string $operation,array $definition,array $ids,callable $lookup): array
    {
        $config=['operation'=>$operation,'body_mode'=>'fields','parameters'=>[]];
        $path=$definition['path']; $prefix=explode('.',$operation)[0];
        $missing=static fn(string $reason):array=>['status'=>'skipped','reason'=>$reason];
        $lookupId=static function(string $parent) use($lookup):array {
            if (!preg_match('~^/api/v4/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D',$parent)) return ['status'=>'failed','error'=>'Malformed prerequisite path: '.$parent];
            $result=$lookup($parent);
            if (($result['status']??'')==='available' && !empty($result['id'])) return ['status'=>'ready','id'=>$result['id']];
            return ['status'=>in_array($result['status']??'', ['empty','unavailable'],true)?'skipped':'failed',
                'reason'=>$result['reason']??'No existing prerequisite identifier','prerequisite_path'=>$parent,'prerequisite_error'=>$result['error']??null];
        };
        if ($operation==='custom') $config['request_path']='/api/v4/account';
        $entityId=$ids[$prefix]??0;
        $entityIdInId=in_array($prefix,['leads','contacts','companies','customers','tasks'],true)
            && in_array($path,['/api/v4/'.$prefix.'/{id}','/api/v4/'.$prefix.'/{id}/subscriptions'],true);
        $needsEntity=str_contains($path,'{entity_id}') || $entityIdInId || (str_contains($operation,'notes') && str_contains($path,'{id}'));
        if ($needsEntity && in_array($prefix,['customers','customer_transactions'],true)) {
            $customer=$lookupId('/api/v4/customers'); if ($customer['status']!=='ready') return $customer;
            $entityId=$customer['id'];
        }
        if ($needsEntity && !$entityId) return $missing('No existing entity ID; creating contacts, companies or customers is forbidden');
        if (str_contains($path,'{entity_id}')) $config['entity_id']=$entityId;
        if (str_contains($path,'{pipeline_id}')) {
            if (empty($ids['pipeline_id'])) return $missing('No pipeline ID');
            $config['pipeline_id']=$ids['pipeline_id'];
        }
        if (str_contains($path,'{catalog_id}')) {
            $catalog=$lookupId('/api/v4/catalogs'); if ($catalog['status']!=='ready') return $catalog;
            $config['catalog_id']=$catalog['id'];
        }
        if (str_contains($path,'{id}')) {
            if ($entityIdInId) $config['id']=$entityId;
            else {
                if (!str_ends_with($path,'/{id}')) return ['status'=>'failed','error'=>'Unknown nested ID binding in read catalog: '.$path];
                $parent=substr($path,0,-5);
                foreach (['entity_id','catalog_id','pipeline_id'] as $parameter) $parent=str_replace('{'.$parameter.'}',(string)($config[$parameter]??''),$parent);
                if (str_contains($operation,'notes')) $parent='/api/v4/'.$prefix.'/'.$entityId.'/notes';
                $record=$lookupId($parent); if ($record['status']!=='ready') return $record;
                $config['id']=$record['id'];
            }
        }
        // The production catalog builder validates the final concrete URL as well.
        WorkflowAmoReadCatalog::build($config);
        if (!str_contains($path,'{id}') && !in_array($operation,['custom','event_types'],true)) $config['parameters']=[['name'=>'limit','value'=>10],['name'=>'page','value'=>1]];
        return ['status'=>'ready','config'=>$config];
    }

    public static function classifyReadPrerequisiteError(string $path,string $error,bool $customersDisabledVerified=false): array
    {
        $failure=['status'=>'failed','error'=>$error,'path'=>$path];
        if (!preg_match('~^amoCRM API v4 error: GET '.preg_quote($path,'~').' returned ([0-9]{3}): (.+)$~sD',$error,$match)) return $failure;
        $body=json_decode($match[2],true); $status=(int)$match[1];
        if (!is_array($body) || (isset($body['status']) && (int)$body['status']!==$status)) return $failure;
        $detail=$body['detail']??null;
        if (in_array($path,['/api/v4/customers/custom_fields','/api/v4/customers/segments','/api/v4/customers/segments/custom_fields'],true) && $status===422 && $detail==='Customers disabled') {
            return ['status'=>'unavailable','capability'=>'customers_disabled','reason'=>'Customers are explicitly disabled in this account','error'=>$error,'path'=>$path];
        }
        if ($path==='/api/v4/customers' && $status===400 && $detail==='Error 426.' && $customersDisabledVerified) {
            return ['status'=>'unavailable','capability'=>'customers_disabled','reason'=>'Customers are disabled, independently confirmed by the segments API','error'=>$error,'path'=>$path];
        }
        if ($path==='/api/v4/customers/transactions' && $status===404 && $detail==='Transactions not found') {
            return ['status'=>'empty','reason'=>'No customer transaction exists for an ID-based read','error'=>$error,'path'=>$path];
        }
        return $failure;
    }

    private function node(string $id,string $type,array $config,?callable $verify=null,bool $expectFailure=false): array
    {
        if ($this->cancellationRequested) throw new RuntimeException('Acceptance run cancelled');
        $input=['qa_run_id'=>$this->marker,'account'=>['id'=>$this->account->id,'subdomain'=>$this->account->subdomain],
            'lead'=>['id'=>$this->leadId], 'contact'=>['id'=>$this->contactId]];
        $context=(new WorkflowContext($input))->setTriggerData($input)->setWorkflowId($this->source->id)->setTriggeredBy($this->source->user_id);
        $case=['id'=>$id,'type'=>$type,'provenance'=>'live_node','config'=>$config,'trigger_data'=>$input,'expected_outcome'=>$expectFailure?'validation_error':'success'];
        try {
            $session=app(WorkflowDebugger::class)->executeNode(['id'=>$id,'type'=>$type,'config'=>$config],$context->toArray(),$input,$this->source->id,$this->source->user_id,true);
            if ($this->cancellationRequested) throw new RuntimeException('Acceptance run cancelled');
            $result=$session['results'][0]??[];
            $case['result']=$result;
            $failed=($session['status']??null)==='failed';
            $completed=($session['status']??null)==='completed' && ($result['status']??null)==='completed';
            if ($expectFailure) {
                // Accept only this case's input error; transport, billing and OAuth failures are regressions.
                $pattern=match($id) {
                    'missing_note_text'=>'~^(?:Ошибка проверки: )?(?:Не заполнен|Заполните) текст примечания\.$~u',
                    'invalid_salesbot_config'=>'~^(?:Ошибка проверки: )?(?:Укажите корректные ID Salesbot и сущности\.|bot_id: нужен положительный целый ID или переменная\.)$~ui',
                    'invalid_contact_id'=>'~^(?:Ошибка проверки: )?(?:Не найден ID контакта amoCRM\.|target_entity_id: нужен положительный целый ID или переменная\.)$~u',
                    'invalid_update_json'=>'~^(?:Ошибка проверки: )?Некорректное JSON-тело: [^\r\n]+$~u',
                    default=>null,
                };
                $error=(string)($result['error']??$session['error']??'');
                $mutations=[];
                foreach ((array)($result['output']['amo_exchange']??[]) as $exchange) {
                    $method=strtoupper((string)($exchange['request']['method']??''));
                    if (!in_array($method,['GET','HEAD'],true)) $mutations[]=$exchange['request']??$exchange;
                }
                $matches=$pattern!==null && preg_match($pattern,$error)===1;
                $case['verification']=[
                    'passed'=>$failed && in_array($result['status']??'',['error','validation_error'],true) && $matches && $mutations===[],
                    'expected_error_pattern'=>$pattern,
                    'actual_error'=>$error,
                    'expected_validation_error_matched'=>$matches,
                    'no_mutating_amo_requests'=>$mutations===[],
                    'mutating_amo_requests'=>$mutations,
                ];
            } else $case['verification']=$verify && $completed ? $verify($result['output']??[]) : ['passed'=>$completed];
            $case['status']=($expectFailure?$failed:$completed)&&($case['verification']['passed']??false)?'passed':'failed';
            if ($this->recurringStatePath && !$expectFailure && $case['status']==='failed') {
                $reason=self::capabilitySkipReason($id,(string)($result['error']??$session['error']??''),$this->customersDisabledVerified);
                if ($reason!==null) { $case['status']='skipped'; $case['reason']=$reason; }
            }
        } catch (Throwable $e) {
            if ($this->cancellationRequested) throw $e;
            $result=[]; $case['status']='failed'; $case['error']=$e->getMessage();
        }
        $this->report['cases'][]=$case;
        $this->save();
        fwrite(STDERR,$id.': '.$case['status'].PHP_EOL);
        return $result;
    }

    private function apiCase(string $id,string $method,string $path,array $body): void
    {
        if ($this->cancellationRequested) throw new RuntimeException('Acceptance run cancelled');
        $case=['id'=>$id,'provenance'=>'live_event_stimulus','request'=>compact('method','path','body')];
        try {
            $case['response']=$this->client->requestV4($method,$path,$body);
            $case['status']='passed';
            if ($method==='PATCH' && preg_match('#^/api/v4/tasks/([1-9][0-9]*)$#',$path,$match)) {
                $expected=array_intersect_key($body,array_flip(['text','is_completed','entity_id']));
                $case['verification']=$this->verifyEntity('tasks',(int)$match[1],$expected);
                if (!$case['verification']['passed']) $case['status']='failed';
            }
        }
        catch (Throwable $e) {
            if ($this->cancellationRequested) throw $e;
            $case['status']='failed'; $case['error']=$e->getMessage();
        }
        $this->report['cases'][]=$case; $this->save();
    }

    private function verifyEntity(string $kind,int $id,array $expected): array
    {
        if (!$id) return ['passed'=>false,'reason'=>'Result did not contain entity_id'];
        $actual=$this->get($kind,$id); $differences=[];
        foreach ($expected as $key=>$value) if (data_get($actual,$key)!=$value) $differences[$key]=['expected'=>$value,'actual'=>data_get($actual,$key)];
        return ['passed'=>$differences===[],'readback'=>$actual,'differences'=>$differences];
    }

    private function linkCheck(bool $expected): array
    {
        $actual=$this->client->requestV4('GET','/api/v4/leads/'.$this->leadId.'/links');
        $found=false;
        foreach ($actual['_embedded']['links']??[] as $link) if ((int)$link['to_entity_id']===$this->contactId && $link['to_entity_type']==='contacts') $found=true;
        return ['passed'=>$found===$expected,'readback'=>$actual];
    }

    private function collectEvents(int $waitSeconds): void
    {
        if (!$this->observer) return;
        $deadline=time()+$waitSeconds;
        do {
            $runs=DB::table('workflow_runs')->where('workflow_id',$this->observer->id)->where('id','>',$this->observerAfterRunId)->orderBy('id')->limit(200)->get();
            $events=[];
            foreach ($runs as $run) {
                $context=json_decode($run->context_data??'{}',true)??[];
                $payload=$context['trigger_data']['payload']??[];
                $normalized=app(WorkflowAmoCrmWebhookPayloadNormalizer::class)->normalize($payload);
                $steps=DB::table('workflow_run_steps')->where('workflow_run_id',$run->id)->orderBy('id')->get()->map(function($s){
                    foreach (['input_data','output_data'] as $key) $s->$key=json_decode($s->$key??'null',true);
                    return (array)$s;
                })->all();
                $events[]=['provenance'=>'live_amocrm_webhook','run_id'=>$run->id,'status'=>$run->status,'raw_body'=>$context['trigger_data']['raw_body']??null,'payload'=>$payload,'normalized'=>$normalized['events'],'steps'=>$steps];
            }
            $this->report['events']=$events; $this->save();
            if ($waitSeconds) usleep(1000000);
        } while(time()<$deadline);
    }

    private function exportHistory(): void
    {
        $ids=Workflow::withoutGlobalScopes()->where('user_id',$this->source->user_id)->pluck('id');
        foreach (DB::table('workflow_runs')->whereIn('workflow_id',$ids)->orderByDesc('id')->limit(60)->get() as $run) {
            $steps=DB::table('workflow_run_steps')->where('workflow_run_id',$run->id)->orderBy('id')->get()->map(function($s){
                foreach(['input_data','output_data'] as $key) $s->$key=json_decode($s->$key??'null',true);
                return (array)$s;
            })->all();
            $this->report['historical_runs'][]=['provenance'=>'historical_execution','id'=>$run->id,'workflow_id'=>$run->workflow_id,'status'=>$run->status,'created_at'=>$run->created_at,'context'=>json_decode($run->context_data??'{}',true),'steps'=>$steps];
        }
    }

    private function closeAllLeads(): void
    {
        foreach ($this->list('leads') as $lead) if (!in_array((int)$lead['status_id'],[142,143],true)) $this->client->requestV4('PATCH','/api/v4/leads/'.$lead['id'],['status_id'=>143]);
    }

    private function cleanup(): void
    {
        $cleanup=function(string $name,callable $fn):void {
            try { $this->report['cleanup'][$name]=['ok'=>true,'result'=>$fn()]; }
            catch(Throwable $e) { $this->report['cleanup'][$name]=['ok'=>false,'error'=>$e->getMessage()]; }
            try { $this->save(); }
            catch(Throwable $e) {
                $this->report['report_write_errors'][] = $e->getMessage();
                fwrite(STDERR, 'Report write failed during cleanup; continuing account recovery.'.PHP_EOL);
            }
        };
        // No business mutations are permitted when preflight failed before pausing.
        if ($this->mutationsPrepared) {
            foreach ($this->restore as $path=>$body) $cleanup('restore_'.$path,function() use($path,$body) {
                $this->client->requestV4('PATCH','/api/v4/'.$path,$body);
                [$entity,$id]=explode('/',$path,2);
                $verified=$this->verifyEntity($entity,(int)$id,$body);
                if(!$verified['passed']) throw new RuntimeException('Original entity fields were not restored');
                return $verified;
            });
            if($this->leadId && $this->contactId && (!$this->recurringStatePath || $this->qaLinkMayExist)) $cleanup('unlink_qa_contact',function() {
                $linked=$this->linkCheck(true);
                if($linked['passed']) $this->client->requestV4('POST','/api/v4/leads/'.$this->leadId.'/unlink',[
                    ['to_entity_id'=>$this->contactId,'to_entity_type'=>'contacts'],
                ]);
                $verified=$this->linkCheck(false);
                if(!$verified['passed']) throw new RuntimeException('Existing contact remains linked to the QA lead');
                return $verified;
            });
            foreach ($this->tasks as $id) $cleanup('complete_task_'.$id,function() use($id) {
                $this->client->requestV4('PATCH','/api/v4/tasks/'.$id,['is_completed'=>true]);
                $verified=$this->verifyEntity('tasks',$id,['is_completed'=>true]);
                if(!$verified['passed']) throw new RuntimeException('QA task remains incomplete');
                return $verified;
            });
            $cleanup('close_all_leads',function(){
                if ($this->recurringStatePath) {
                    if ($this->leadId) $this->client->requestV4('PATCH','/api/v4/leads/'.$this->leadId,['status_id'=>143]);
                } else $this->closeAllLeads();
                $leads=$this->list('leads');
                if(array_filter($leads,fn($l)=>!in_array((int)$l['status_id'],[142,143],true))) throw new RuntimeException('Open leads remain');
                if ($this->recurringStatePath && count($leads)>10) throw new RuntimeException('Account exceeds ten-lead cap');
                return array_map(fn($l)=>['id'=>$l['id'],'status_id'=>$l['status_id']],$leads);
            });
            $cleanup('collect_webhooks',fn()=> $this->collectEvents(10));
            if ($this->recurringStatePath) {
                foreach (['update_lead'=>$this->leadId,'status_lead'=>$this->leadId,'update_contact'=>$this->contactId,'update_task'=>$this->tasks[0]??0] as $event=>$entityId) {
                    if (!$entityId) {
                        // Do not perform an unguarded checkpoint write in cleanup: a full
                        // disk must not prevent the later webhook/workflow restoration.
                        $this->report['cases'][]=['id'=>'webhook:'.$event,'type'=>'webhook','status'=>'skipped','reason'=>'Required QA entity was not available'];
                        continue;
                    }
                    $verification=self::verifyObservedEvent($this->report['events']??[],$event,$entityId);
                    $this->report['cases'][]=['id'=>'webhook:'.$event,'type'=>'webhook','status'=>$verification['passed']?'passed':'failed','verification'=>$verification];
                }
            }
        }
        if ($this->qaWebhookCreationAttempted && $this->hookUrl) $cleanup('remove_qa_webhook',
            fn()=> $this->webhookSubscription()->remove($this->hookUrl));
        if ($this->observer) $cleanup('archive_observer',function(){
            $values=['is_active'=>false];
            if (!$this->recurringStatePath) $values['deleted_at']=now();
            DB::table('workflows')->where('id',$this->observer->id)->where('user_id',$this->source->user_id)->update($values);
            if (DB::table('workflows')->where('id',$this->observer->id)->where('is_active',true)->exists()) throw new RuntimeException('QA observer remains active');
            return ['id'=>$this->observer->id,'history_retained'=>true,'reusable'=>(bool)$this->recurringStatePath];
        });
        if ($this->workflowsPaused && $this->paused!==[]) $cleanup('restore_workflow_activation',function(){
            DB::table('workflows')->whereIn('id',$this->paused)->where('user_id',$this->source->user_id)->whereNull('deleted_at')->update(['is_active'=>true]);
            $active=DB::table('workflows')->whereIn('id',$this->paused)->where('user_id',$this->source->user_id)->whereNull('deleted_at')->where('is_active',true)->count();
            if ($active!==count($this->paused)) throw new RuntimeException('Some previously active workflows were not restored');
            return $this->paused;
        });
        if (isset($this->report['before'])) $cleanup('contact_company_count',function(){
            $contactIds=array_column($this->list('contacts'),'id'); $companyIds=array_column($this->list('companies'),'id');
            sort($contactIds);sort($companyIds);$before=$this->report['before']['contact_ids'];$beforeCompanies=$this->report['before']['company_ids'];sort($before);sort($beforeCompanies);
            if($contactIds!==$before||$companyIds!==$beforeCompanies) throw new RuntimeException('Contact/company IDs changed');
            return ['contacts'=>count($contactIds),'companies'=>count($companyIds),'unchanged'=>true];
        });
    }

    private function list(string $entity): array
    {
        $items=[];
        for($page=1;$page<=20;$page++) {
            $body=$this->client->requestV4('GET','/api/v4/'.$entity,[],['limit'=>250,'page'=>$page]);
            $rows=$body['_embedded'][$entity]??[]; array_push($items,...$rows);
            if(empty($body['_links']['next'])) return $items;
        }
        throw new RuntimeException('Account exceeds bounded acceptance inventory');
    }

    private function get(string $entity,int $id): array { return $this->client->requestV4('GET','/api/v4/'.$entity.'/'.$id); }

    public static function verifyObservedEvent(array $events,string $event,int $entityId): array
    {
        $matched=[]; $failed=[];
        foreach ($events as $received) {
            $normalized=$received['normalized'][$event]??[];
            if (!in_array($entityId,array_map('intval',array_column($normalized['items']??[],'id')),true)) continue;
            $completed=($received['status']??'')==='completed' && !empty($received['steps']);
            foreach ($received['steps']??[] as $step) $completed=$completed && ($step['status']??'')==='completed';
            if ($completed) $matched[]=$received['run_id']; else $failed[]=$received['run_id'];
        }
        return ['passed'=>$matched!==[] && $failed===[],'expected_event'=>$event,'expected_entity_id'=>$entityId,
            'completed_run_ids'=>$matched,'failed_or_unfinished_run_ids'=>$failed,
            'reason'=>$matched===[]?'No successfully executed webhook for the expected QA entity was received':($failed!==[]?'Some matching webhook runs failed or did not finish':null)];
    }

    public static function capabilitySkipReason(string $id,string $error,bool $customersDisabledVerified=false): ?string
    {
        $exact=[
            'read:transactions.list'=>'amoCRM API v4 вернул ошибку 404 на GET /api/v4/customers/transactions: Transactions not found',
            'read:segments.list'=>'amoCRM API v4 вернул ошибку 422 на GET /api/v4/customers/segments: Customers disabled',
            'read:segment_fields.list'=>'amoCRM API v4 вернул ошибку 422 на GET /api/v4/customers/segments/custom_fields: Customers disabled',
        ];
        if (isset($exact[$id]) && $error===$exact[$id]) return 'This technical account has no available customer capability/data for the requested operation';
        if (str_starts_with($id,'read:')) {
            $path=WorkflowAmoReadCatalog::operations()[substr($id,5)]['path']??'';
            if (str_starts_with($path,'/api/v4/customers') && !str_contains($path,'{')) {
                if ($error==='amoCRM API v4 вернул ошибку 422 на GET '.$path.': Customers disabled') return 'Customers are explicitly disabled for this operation';
                if ($customersDisabledVerified && $error==='amoCRM API v4 вернул ошибку 400 на GET '.$path.': Error 426.') return 'Customers are disabled, independently confirmed by the segments API';
            }
        }
        return null;
    }

    private function save(bool $persistState = true): void
    {
        if ($persistState && $this->recurringStateStarted) $this->saveRecurringState();
        $this->report['recovery']=['restore_fields'=>$this->restore,'task_ids'=>$this->tasks,'paused_workflow_ids'=>$this->paused,
            'observer_workflow_id'=>$this->observer?->id,'qa_webhook_creation_attempted'=>$this->qaWebhookCreationAttempted];
        $data=$this->report;
        $data['_redaction_secrets']=[
            $this->account->access_token,$this->account->refresh_token,$this->account->client_secret,
            $this->account->code,$this->hookUrl,
        ];
        $data=WorkflowReportSanitizer::sanitize($data);
        unset($data['_redaction_secrets']);
        $this->writePrivateJson($this->reportPath,$data);
    }

    private function writePrivateJson(string $path,array $data): void
    {
        $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
        $dir=dirname($path);
        if(!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Could not create acceptance report directory');
        // Write and secure the complete replacement before publishing it to readers.
        $temporary=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        try {
            $handle=@fopen($temporary,'xb');
            if($handle===false) throw new RuntimeException('Could not create acceptance report');
            try {
                if(!@chmod($temporary,0600)) throw new RuntimeException('Could not restrict acceptance report permissions');
                $offset=0;
                while($offset<strlen($json)) {
                    $written=fwrite($handle,substr($json,$offset));
                    if($written===false||$written===0) throw new RuntimeException('Could not write complete acceptance report');
                    $offset+=$written;
                }
                if(!fflush($handle)) throw new RuntimeException('Could not flush acceptance report');
                if(function_exists('fsync') && !fsync($handle)) throw new RuntimeException('Could not sync acceptance checkpoint to disk');
            } finally { fclose($handle); }
            if(!@rename($temporary,$path)) throw new RuntimeException('Could not publish acceptance report');
        } finally {
            if(is_file($temporary)) @unlink($temporary);
        }
    }
}
