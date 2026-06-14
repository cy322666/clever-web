<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => env('HORIZON_WAIT_DEFAULT_SECONDS', 60),
        'redis:workflows' => 30,
        'redis:workflow-webhooks' => 15,
        'redis:getcourse_form' => 120,
        'redis:getcourse_order' => 120,
        'redis:bizon_form' => 120,
        'redis:bizon_export' => 120,
        'redis:call_transcription' => 120,
        'redis:tilda_form' => 60,
        'redis:alfacrm_hook' => 60,
        'redis:alfacrm_record' => 60,
        'redis:distribution_transaction' => 120,
        'redis:yclients_record' => 120,
        'redis:import_excel' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => env('HORIZON_TRIM_RECENT_MINUTES', 120),
        'pending' => env('HORIZON_TRIM_PENDING_MINUTES', 120),
        'completed' => env('HORIZON_TRIM_COMPLETED_MINUTES', 120),
        'recent_failed' => env('HORIZON_TRIM_FAILED_MINUTES', 10080),
        'failed' => env('HORIZON_TRIM_FAILED_MINUTES', 10080),
        'monitored' => env('HORIZON_TRIM_MONITORED_MINUTES', 10080),
        'silenced' => env('HORIZON_TRIM_SILENCED_MINUTES', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => env('HORIZON_METRIC_JOB_SNAPSHOTS', 48),
            'queue' => env('HORIZON_METRIC_QUEUE_SNAPSHOTS', 48),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Clever Platform Monitoring
    |--------------------------------------------------------------------------
    |
    | These tags are monitored automatically by the scheduler and before
    | Horizon starts in Supervisor. No manual setup in Horizon UI is needed.
    |
    */

    'platform' => [
        'monitor_tags' => [
            'workflow',
            'widget:workflows',
            'widget:tilda',
            'widget:getcourse',
            'widget:bizon',
            'widget:yclients',
            'widget:alfacrm',
            'widget:distribution',
            'widget:import-excel',
            'widget:call-transcription',
            'platform:catalog',
            'integration:amoCRM',
            'queue:workflows',
            'queue:workflow-webhooks',
            'queue:tilda_form',
            'queue:getcourse_form',
            'queue:getcourse_order',
            'queue:bizon_form',
            'queue:bizon_export',
            'queue:call_transcription',
            'queue:yclients_record',
            'queue:alfacrm_hook',
            'queue:alfacrm_record',
            'queue:distribution_transaction',
            'queue:import_excel',
            'queue:default',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option
    | is provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => env('HORIZON_FAST_TERMINATION', true),

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => env('HORIZON_MEMORY_LIMIT', 256),

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    */

    'defaults' => [
        'supervisor-workflows' => [
            'connection' => 'redis',
            'queue' => ['workflows', 'workflow-webhooks'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 2,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 900,
            'nice' => 0,
        ],

        'supervisor-integrations' => [
            'connection' => 'redis',
            'queue' => [
                'call_transcription',
                'import_excel',
                'getcourse_form',
                'getcourse_order',
                'bizon_form',
                'bizon_export',
                'tilda_form',
                'alfacrm_hook',
                'alfacrm_record',
                'distribution_transaction',
                'default',
            ],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 3600,
            'maxJobs' => 800,
            'memory' => 512,
            'tries' => 3,
            'timeout' => 300,
            'nice' => 0,
        ],

        'supervisor-yclients' => [
            'connection' => 'redis',
            'queue' => ['yclients_record'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 3600,
            'maxJobs' => 800,
            'memory' => 512,
            'tries' => 2,
            'timeout' => 300,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-workflows' => [
                'maxProcesses' => env('HORIZON_WORKFLOWS_MAX_PROCESSES', 6),
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
            'supervisor-integrations' => [
                'maxProcesses' => env('HORIZON_INTEGRATIONS_MAX_PROCESSES', 6),
                'balanceMaxShift' => 2,
                'balanceCooldown' => 5,
            ],
            'supervisor-yclients' => [
                'maxProcesses' => env('HORIZON_YCLIENTS_MAX_PROCESSES', 8),
                'balanceMaxShift' => 2,
                'balanceCooldown' => 3,
            ],
        ],

        'staging' => [
            'supervisor-workflows' => [
                'maxProcesses' => env('HORIZON_WORKFLOWS_MAX_PROCESSES', 3),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
            'supervisor-integrations' => [
                'maxProcesses' => env('HORIZON_INTEGRATIONS_MAX_PROCESSES', 3),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
            'supervisor-yclients' => [
                'maxProcesses' => env('HORIZON_YCLIENTS_MAX_PROCESSES', 3),
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
        ],

        'local' => [
            'supervisor-workflows' => [
                'maxProcesses' => 2,
            ],
            'supervisor-integrations' => [
                'maxProcesses' => 2,
            ],
            'supervisor-yclients' => [
                'maxProcesses' => 2,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
