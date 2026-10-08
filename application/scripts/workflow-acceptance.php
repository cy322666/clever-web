<?php

declare(strict_types=1);

$root=getenv('WORKFLOW_APP_ROOT') ?: dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// A temporary copy may run against deployed services without deploying application code.
$testing=getenv('WORKFLOW_TESTING_SOURCE') ?: $root.'/app/Services/Workflows/Testing';
foreach (['WorkflowReportSanitizer','WorkflowLiveAcceptance'] as $class) require_once $testing.'/'.$class.'.php';
$options=getopt('', ['workflow:','expected-domain:','report:','recurring-state:','expected-account-id:','execute','read-only']);
if ((!isset($options['execute'])&&!isset($options['read-only'])) || !isset($options['workflow'],$options['expected-domain'],$options['report'])) {
    fwrite(STDERR,"Usage: php scripts/workflow-acceptance.php --workflow=15 --expected-domain=widgetscenario --report=/private/path/live.json --read-only|--execute\n");
    exit(2);
}
$runner=new App\Services\Workflows\Testing\WorkflowLiveAcceptance;
try {
if (isset($options['recurring-state'])) {
    if (!isset($options['execute']) || isset($options['read-only'])) {
        fwrite(STDERR,"Recurring mode requires --execute without --read-only.\n");
        exit(2);
    }
    $outcome=$runner->runRecurring((int)$options['workflow'],$options['expected-domain'],$options['report'],$options['recurring-state'],isset($options['expected-account-id'])?(int)$options['expected-account-id']:null);
} else {
    $method=isset($options['read-only'])?'readOnly':'run';
    $outcome=$runner->$method((int)$options['workflow'],$options['expected-domain'],$options['report']);
}
} catch (\Throwable $error) {
    // Early OAuth/API failures happen before the runner creates its normal report.
    // Persist them so the parent and scheduler retain the real cause and owner.
    $diagnostics = app(\App\Services\Workflows\Testing\WorkflowAcceptanceDiagnostics::class);
    $report = array_replace([
        'schema_version' => 1, 'suite' => 'recurring_live_acceptance',
        'started_at' => now()->toIso8601String(), 'cases' => [], 'cleanup' => [],
    ], $diagnostics->context((int) $options['workflow'], $options['expected-domain']));
    if (is_file($options['report'])) {
        $partial = json_decode(file_get_contents($options['report']), true);
        if (is_array($partial)) $report = array_replace($report, $partial);
    }
    $report['fatal_error'] = $error->getMessage();
    $report['finished_at'] = now()->toIso8601String();
    $report['counts'] = array_count_values(array_column($report['cases'], 'status'));
    $directory = dirname($options['report']);
    \App\Services\Workflows\Testing\WorkflowAcceptanceStorage::prepareDirectory($directory);
    $temporary = tempnam($directory, '.failure-');
    if ($temporary === false || realpath(dirname($temporary)) !== realpath($directory)) {
        throw new \RuntimeException('Cannot write acceptance failure report');
    }
    try {
        \App\Services\Workflows\Testing\WorkflowAcceptanceStorage::secureFile($temporary, $directory);
        $json = json_encode(\App\Services\Workflows\Testing\WorkflowReportSanitizer::sanitize($report), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || ! rename($temporary, $options['report'])) {
            throw new \RuntimeException('Cannot save acceptance failure report');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
    fwrite(STDERR, "Acceptance stopped; the cause is saved in the private report.\n");
    exit(1);
}
$report=json_decode(file_get_contents($options['report']),true,512,JSON_THROW_ON_ERROR);
echo json_encode(['report'=>$options['report'],'counts'=>$report['counts'],'fatal_error'=>$report['fatal_error']??null,'cleanup'=>$report['cleanup']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit(isset($outcome['fatal_error'])||!empty($outcome['report_write_errors'])||($outcome['counts']['failed']??0)>0||in_array(false,array_column($outcome['cleanup']??[],'ok'),true)?1:0);
