<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RuntimeFileAccessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/runtime-access-'.bin2hex(random_bytes(8));
        foreach (['app', 'bootstrap', 'config', 'routes', 'resources/views', 'vendor', 'public'] as $directory) {
            mkdir($this->directory.'/'.$directory, 0755, true);
        }
        foreach (['bootstrap/app.php', 'public/index.php', 'vendor/autoload.php', 'app/Example.php'] as $file) {
            file_put_contents($this->directory.'/'.$file, '<?php');
        }
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_readable_source_passes_under_the_service_user(): void
    {
        $result = $this->check();
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->assertSame(1, $result->getExitCode());
            $this->assertStringContainsString('not root', $result->getErrorOutput());
            return;
        }
        $this->assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $this->assertStringContainsString('Runtime access check passed', $result->getOutput());
    }

    public function test_unreadable_php_fails_without_loading_application_code(): void
    {
        $file = $this->directory.'/app/Example.php';
        chmod($file, 0000);
        try {
            $this->assertFailure('Unreadable runtime file: app/Example.php');
        } finally {
            chmod($file, 0644);
        }
    }

    public function test_unsearchable_directory_fails(): void
    {
        $directory = $this->directory.'/app';
        chmod($directory, 0600);
        try {
            $this->assertFailure('Unreadable or unsearchable directory: app');
        } finally {
            chmod($directory, 0755);
        }
    }

    public function test_missing_autoloader_fails(): void
    {
        unlink($this->directory.'/vendor/autoload.php');
        $this->assertFailure('Unreadable entrypoint: vendor/autoload.php');
    }

    public function test_missing_required_directory_fails(): void
    {
        rmdir($this->directory.'/routes');
        $this->assertFailure('Unreadable or unsearchable directory: routes');
    }

    private function assertFailure(string $message): void
    {
        $result = $this->check();
        $this->assertSame(1, $result->getExitCode());
        $this->assertStringContainsString(
            function_exists('posix_geteuid') && posix_geteuid() === 0 ? 'not root' : $message,
            $result->getErrorOutput(),
        );
    }

    private function check(): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/check-runtime-access.php', $this->directory]);
        $process->run();
        return $process;
    }
}
