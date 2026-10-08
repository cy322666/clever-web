<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$options=getopt('', ['live:','output:','junit:','closure:']);
if (empty($options['output'])) throw new RuntimeException('Specify --output=/absolute/path.json and optionally --live=/absolute/live.json --junit=/absolute/tests.xml');
$live=!empty($options['live']) ? json_decode(file_get_contents($options['live']),true,512,JSON_THROW_ON_ERROR) : [];
$events=App\Services\Workflows\Testing\WorkflowEventSamples::samples();
$nodes=App\Services\Workflows\Testing\WorkflowNodeSamples::samples();
foreach ($events as $type=>&$event) {
    $event['live_observations']=[];
    $event['historical_observations']=[];
    foreach ($live['events']??[] as $observation) {
        foreach ($observation['normalized']??[] as $code=>$normalized) {
            if ('amocrm-'.str_replace('_','-',$code)===$type || 'amocrm-'.$code===$type) $event['live_observations'][]=$observation;
        }
    }
    foreach ($live['historical_runs']??[] as $historical) {
        $trigger=$historical['context']['trigger_data']??[];
        $code=$trigger['event']??'';
        if($code===$type || 'amocrm-'.str_replace('_','-',$code)===$type) {
            $event['historical_observations'][]=['provenance'=>'historical_execution','run_id'=>$historical['id'],'created_at'=>$historical['created_at'],'trigger_data'=>$trigger];
        }
    }
    if($type==='generic-webhook' && ($live['events']??[])!==[]) $event['live_observations']=$live['events'];
    $event['coverage']=['contract'=>'synthetic_example','live'=>$event['live_observations']===[]?'not_observed':'observed'];
    if ($event['live_observations']===[]) $event['live_limitation']=match (true) {
        str_contains($type,'contact') && (str_contains($type,'add')||str_contains($type,'delete')||str_contains($type,'restore'))=>'Новые контакты и удаление существующих запрещены условиями прогона.',
        str_contains($type,'company')=>'В аккаунте нет компаний; новые компании не создавались.',
        str_contains($type,'message'),str_contains($type,'talk'),str_contains($type,'template')=>'Нужны отдельный тестовый канал/собеседник и реальное событие канала.',
        str_contains($type,'delete'),str_contains($type,'restore')=>'Удаление/восстановление существующих данных не выполнялось.',
        default=>'Это событие не получено в этом реальном прогоне. Контрактный пример не подтверждает доставку события.',
    };
}
unset($event);
foreach ($nodes as $type=>&$node) {
    $node['live_executions']=array_values(array_filter($live['cases']??[],fn($c)=>($c['type']??null)===$type));
    $positive=array_filter($node['live_executions'],fn($c)=>($c['status']??null)==='passed'&&($c['expected_outcome']??null)==='success');
    $node['coverage']=['contract'=>'synthetic_example','live'=>$positive!==[]?'verified_positive':($node['live_executions']!==[]?'negative_or_incomplete':'not_executed')];
    $node['historical_executions']=[];
    foreach($live['historical_runs']??[] as $historical) foreach($historical['steps']??[] as $step) {
        if(($step['action_type']??$step['type']??null)===$type) $node['historical_executions'][]=['provenance'=>'historical_execution','run_id'=>$historical['id'],'created_at'=>$historical['created_at'],'step'=>$step];
    }
    if ($positive===[]) $node['live_limitation']=match ($type) {
        'amocrm_create_contact'=>'Не выполнялась: пользователь запретил создавать новые контакты.',
        'amocrm_create_company','amocrm_update_company_fields'=>'Не выполнялась: компаний в аккаунте нет, новые не создавались.',
        'telegram_send_message','multi_channel_notification'=>'Не заданы отдельный тестовый адресат/чат; отправки сообщений не выполнялись.',
        'amocrm_start_salesbot'=>'Проверена ошибка конфигурации; запуск бота с перепиской требует отдельного тестового бота/получателя.',
        'amocrm_distribution_queue'=>'Для успешного прогона нужна выделенная тестовая очередь распределения.',
        default=>($node['availability']??'')==='unsupported'?'Обработчик зарегистрирован для старых схем, но действие пока не поддерживается.':'Успешное реальное выполнение не подтверждено.',
    };
}
unset($node);
$junit=null;
if (!empty($options['junit'])) {
    $xml=simplexml_load_file($options['junit']);
    $junit=['file'=>basename($options['junit']),'suites'=>[]];
    foreach ($xml->testsuite as $s) $junit['suites'][]=array_map('strval',iterator_to_array($s->attributes()));
}
$report=['schema_version'=>1,'generated_at'=>gmdate(DATE_ATOM),'purpose'=>'JSON для анализа входов/выходов и упрощения интерфейса потоков',
    'execution_scope'=>($live['suite']??'')==='read_only_acceptance'
        ? 'Текущий реальный прогон проверил только чтение. Полный прогон с созданием QA-данных/временной остановкой сценариев ожидает отдельного подтверждения после блокировки автоматической проверкой разрешений.'
        : 'Объём реально выполненных проверок указан в coverage и live_run; остальные примеры синтетические.',
    'provenance_legend'=>[
        'synthetic_contract'=>'Контролируемый пример. Сравнивается с реальным обработчиком в автотесте с подменой внешнего API. Не данные amoCRM аккаунта.',
        'live_node'=>'Нода реально вызвана серверным отладчиком, данные проверены обратным чтением где применимо.',
        'live_amocrm_webhook'=>'Настоящий webhook amoCRM, принят публичным endpoint и сохранён в истории отдельного QA-потока.',
        'historical_execution'=>'Ранее сохранённый запуск выбранного аккаунта; не результат текущего теста.',
        'skipped'=>'Не выполнено; причину нельзя считать успешной проверкой.',
    ],'summary'=>['event_types'=>count($events),'node_types'=>count($nodes),'live_counts'=>$live['counts']??[],
        'observed_event_types'=>count(array_filter($events,fn($e)=>$e['live_observations']!==[])),
        'verified_node_types'=>count(array_filter($nodes,fn($n)=>$n['coverage']['live']==='verified_positive')),
        'historical_event_types'=>count(array_filter($events,fn($e)=>$e['historical_observations']!==[])),
        'historical_node_types'=>count(array_filter($nodes,fn($n)=>$n['historical_executions']!==[]))],
    'automated_tests'=>$junit,'events'=>array_values($events),'nodes'=>array_values($nodes),'live_run'=>$live,
    'ux_review_notes'=>[
        'Показать результат и полезные поля сущности на первом уровне.',
        'request, amo_exchange, response headers, URL, _links, context_masks и служебные переменные убрать под «Технические детали».',
        'Не терять доступ к исходному JSON: он нужен для выражений, отладки и копирования.',
        'У get_contact одна сущность повторяется в верхнем уровне, contact и data; UI может показывать её один раз.',
        'Разделять «ошибка запуска», «нода не выполнялась», «пустой результат» и «ошибка amoCRM».',
    ], 'ux_message_examples'=>[
        ['source'=>'live_read_only','technical'=>'422 Customers disabled','user_message'=>'В этом аккаунте amoCRM отключён раздел «Покупатели». Включите его или выберите другой запрос.'],
        ['source'=>'live_read_only','technical'=>'404 Transactions not found','user_message'=>'В аккаунте не найдены транзакции покупателей.','note'=>'Исходный код и ответ оставить в технических деталях; перед изменением бизнес-логики подтвердить трактовку endpoint.'],
        ['source'=>'synthetic_contract','technical'=>'Telegram 403','user_message'=>'Бот не может отправить сообщение в этот чат.','note'=>'Уточнять причину по Telegram description; 403 сам по себе не означает неверный токен.'],
    ]];
if(!empty($options['closure'])) $report['requested_deal_closure']=json_decode(file_get_contents($options['closure']),true,512,JSON_THROW_ON_ERROR);
$clean=App\Services\Workflows\Testing\WorkflowReportSanitizer::sanitize($report);
file_put_contents($options['output'],json_encode($clean,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo json_encode($report['summary'],JSON_UNESCAPED_UNICODE).PHP_EOL;
