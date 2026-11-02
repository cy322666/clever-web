<?php

namespace Tests\Unit;

use Illuminate\Cache\FileStore;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class AtomicFrameworkCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/clever-cache-test-'.bin2hex(random_bytes(8));
        foreach (['bootstrap/cache', 'config', 'routes', 'app', 'resources/views', 'storage/framework/views',
            'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs'] as $path) {
            mkdir($this->directory.'/'.$path, 0775, true);
        }
        symlink(dirname(__DIR__, 2).'/vendor', $this->directory.'/vendor');
        copy(dirname(__DIR__, 2).'/artisan', $this->directory.'/artisan');
        file_put_contents($this->directory.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'extra' => ['laravel' => ['dont-discover' => ['*']]],
        ]));
        file_put_contents($this->directory.'/bootstrap/providers.php', <<<'PHP'
<?php
class CacheFixtureRouteProvider extends Illuminate\Foundation\Support\Providers\RouteServiceProvider {
    public function boot(): void {
        $this->routes(function () { require base_path('routes/web.php'); });
    }
}
PHP);
        file_put_contents($this->directory.'/bootstrap/app.php', <<<'PHP'
<?php
require_once __DIR__.'/providers.php';
$app = new Illuminate\Foundation\Application(dirname(__DIR__));
$app->singleton(Illuminate\Contracts\Console\Kernel::class, Illuminate\Foundation\Console\Kernel::class);
$app->singleton(Illuminate\Contracts\Debug\ExceptionHandler::class, Illuminate\Foundation\Exceptions\Handler::class);
$app->register(CacheFixtureRouteProvider::class);
return $app;
PHP);
        $this->configuration('First release');
        file_put_contents($this->directory.'/config/view.php', <<<'PHP'
<?php return ['paths' => [resource_path('views')],
    'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views'))];
PHP);
        file_put_contents($this->directory.'/config/cache.php', <<<'PHP'
<?php return ['default' => 'file', 'stores' => ['file' => [
    'driver' => 'file', 'path' => storage_path('framework/cache/data')]], 'prefix' => 'test'];
PHP);
        file_put_contents($this->directory.'/routes/web.php', <<<'PHP'
<?php
usleep(150000);
Illuminate\Support\Facades\Route::get('/up', fn () => 'OK')->name('health');
PHP);
        file_put_contents($this->directory.'/resources/views/example.blade.php', 'First release {{ 1 + 1 }}');
    }

    protected function tearDown(): void
    {
        // Do not follow the vendor symlink into the real checkout.
        unlink($this->directory.'/vendor');
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function configuration(string $name): void
    {
        file_put_contents($this->directory.'/config/app.php', '<?php return '.var_export([
            'name' => $name, 'env' => 'testing', 'debug' => true, 'url' => 'http://localhost',
            'key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'cipher' => 'AES-256-CBC',
            'providers' => \Illuminate\Support\ServiceProvider::defaultProviders()->toArray(),
        ], true).';');
    }

    private function build(): Process
    {
        return new Process([PHP_BINARY, '/dev/stdin'],
            $this->directory, [
                'APP_CONFIG_CACHE' => $this->directory.'/bootstrap/cache/config.php',
                'APP_ROUTES_CACHE' => $this->directory.'/bootstrap/cache/routes-v7.php',
                'APP_EVENTS_CACHE' => $this->directory.'/bootstrap/cache/events.php',
                'VIEW_COMPILED_PATH' => $this->directory.'/storage/framework/views',
            ], input: file_get_contents(dirname(__DIR__, 3).'/ops/deploy/cache-framework.php'), timeout: 60);
    }

    public function test_rebuild_keeps_live_caches_available_and_preserves_shared_cache(): void
    {
        $this->build()->mustRun();
        $cache = new FileStore(new Filesystem, $this->directory.'/storage/framework/cache/data');
        $cache->put('scheduler-heartbeat', 'keep-this-heartbeat', 3600);
        $this->configuration('Second release');
        file_put_contents($this->directory.'/resources/views/example.blade.php', 'Second release {{ 2 + 2 }}');
        $files = glob($this->directory.'/bootstrap/cache/{config,events,routes-v7}.php', GLOB_BRACE);
        $this->assertCount(3, $files);
        $views = glob($this->directory.'/storage/framework/views/*.php');
        $this->assertNotEmpty($views);
        $process = $this->build();
        $process->start();
        do {
            foreach (array_merge($files, $views) as $file) {
                clearstatcache(true, $file);
                if (! is_file($file) || filesize($file) === 0) {
                    $this->fail('A live cache disappeared during rebuild: '.$file);
                }
            }
            usleep(10000);
        } while ($process->isRunning());
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $config = require $this->directory.'/bootstrap/cache/config.php';
        $this->assertSame('Second release', $config['app']['name']);
        $this->assertSame($this->directory.'/storage/framework/views', $config['view']['compiled']);
        $this->assertNotEmpty(array_filter($views,
            fn ($file) => str_contains(file_get_contents($file), 'Second release')));
        $this->assertSame('keep-this-heartbeat', $cache->get('scheduler-heartbeat'));
        $this->assertSame([], glob($this->directory.'/bootstrap/cache/.deploy-*'));
        $this->assertSame([], glob($this->directory.'/storage/framework/views/.deploy-*'));
    }

    public function test_failed_build_does_not_remove_or_replace_live_files(): void
    {
        $this->build()->mustRun();
        $files = array_merge(glob($this->directory.'/bootstrap/cache/*.php'),
            glob($this->directory.'/storage/framework/views/*.php'));
        $before = array_map('hash_file', array_fill(0, count($files), 'sha256'), $files);
        file_put_contents($this->directory.'/routes/web.php', '<?php throw new RuntimeException("Build failed");');
        $process = $this->build();
        $process->run();
        $this->assertNotSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        foreach ($files as $index => $file) {
            $this->assertSame($before[$index], hash_file('sha256', $file), $file);
        }
        $this->assertSame([], glob($this->directory.'/bootstrap/cache/.deploy-*'));
        $this->assertSame([], glob($this->directory.'/storage/framework/views/.deploy-*'));
    }
}
