<?php

// Run from the Laravel directory. The deploy keeps this script outside the
// checkout so the same safe cache publication also works during a rollback.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Process\Process;

$directories = [];
try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $destinations = [
        'APP_CONFIG_CACHE' => $app->getCachedConfigPath(),
        'APP_EVENTS_CACHE' => $app->getCachedEventsPath(),
        'APP_ROUTES_CACHE' => $app->getCachedRoutesPath(),
    ];
    $views = $app['config']->get('view.compiled');
    $environment = [];
    $release = bin2hex(random_bytes(8));
    $run = static function (array $command, array $environment = []): void {
        (new Process($command, getcwd(), $environment, timeout: 300))->mustRun(
            static fn ($type, $output) => fwrite($type === Process::ERR ? STDERR : STDOUT, $output)
        );
    };

    try {
        foreach ($destinations as $key => $destination) {
            $directory = dirname($destination).'/.deploy-'.$release;
            if (! is_dir($directory) && ! mkdir($directory, 0775, true)) {
                throw new RuntimeException('Cannot create a cache staging directory.');
            }
            $directories[$directory] = true;
            $environment[$key] = $directory.'/'.basename($destination);
        }
        if (! is_string($views) || $views === '') {
            throw new RuntimeException('The compiled view directory is not configured.');
        }
        $viewStage = $views.'/.deploy-'.$release;
        if (! mkdir($viewStage, 0775, true)) {
            throw new RuntimeException('Cannot create a view staging directory.');
        }
        $directories[$viewStage] = true;

        // Laravel's cache commands clear their destination first. Give them private
        // destinations: a request may already have checked that a live file exists.
        foreach (['config:cache', 'event:cache', 'route:cache'] as $command) {
            $run([PHP_BINARY, 'artisan', $command, '--no-interaction'], $environment);
        }
        $run([PHP_BINARY, 'artisan', 'view:cache', '--no-interaction'], array_merge($environment, [
            // Read fresh configuration so VIEW_COMPILED_PATH affects this build only.
            'APP_CONFIG_CACHE' => $environment['APP_CONFIG_CACHE'].'.views',
            'VIEW_COMPILED_PATH' => $viewStage,
        ]));
        foreach ($environment as $file) {
            if (! is_file($file) || filesize($file) === 0) {
                throw new RuntimeException('A staged framework cache is missing.');
            }
            $run([PHP_BINARY, '-l', $file]);
        }

        // Every rename stays on the destination filesystem. Existing readers see
        // either complete version, and a failed build leaves all live files intact.
        foreach (glob($viewStage.'/*.php') ?: [] as $file) {
            if (! rename($file, $views.'/'.basename($file))) {
                throw new RuntimeException('Cannot publish a compiled view.');
            }
        }
        foreach (['APP_EVENTS_CACHE', 'APP_ROUTES_CACHE', 'APP_CONFIG_CACHE'] as $key) {
            if (! rename($environment[$key], $destinations[$key])) {
                throw new RuntimeException('Cannot publish a framework cache.');
            }
        }
        foreach (ServiceProvider::$optimizeCommands as $command) {
            $run([PHP_BINARY, 'artisan', $command, '--no-interaction']);
        }
        fwrite(STDOUT, "Framework caches published without clearing the shared application cache.\n");
    } finally {
        foreach (array_keys($directories) as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,
                FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        }
    }
} catch (Throwable $exception) {
    // Laravel installs an exception handler during bootstrap. Return a nonzero
    // exit explicitly so a failed build cannot be mistaken for a healthy release.
    fwrite(STDERR, 'Framework cache publication failed ('.get_class($exception).").\n");
    exit(1);
}
