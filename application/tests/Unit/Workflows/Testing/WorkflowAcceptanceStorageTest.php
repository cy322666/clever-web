<?php

namespace Tests\Unit\Workflows\Testing;

use App\Services\Workflows\Testing\WorkflowAcceptanceStorage;
use App\Services\Workflows\Testing\WorkflowLiveAcceptance;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

class WorkflowAcceptanceStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/acceptance-storage-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            if (is_dir($path) && ! is_link($path)) {
                rmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_private_directory_inherits_its_parent_owner_and_group(): void
    {
        $child = $this->directory.'/reports';
        WorkflowAcceptanceStorage::prepareDirectory($child);

        $this->assertSame(0700, fileperms($child) & 0777);
        $this->assertSame(fileowner($this->directory), fileowner($child));
        $this->assertSame(filegroup($this->directory), filegroup($child));
        WorkflowAcceptanceStorage::prepareDirectory($child);
        $this->assertSame(0700, fileperms($child) & 0777);
    }

    public function test_checkpoint_replacement_keeps_private_permissions_and_directory_owner(): void
    {
        $path = $this->directory.'/state.json';
        file_put_contents($path, '{"phase":"ready"}');
        chmod($path, 0644);
        $runner = new WorkflowLiveAcceptance;
        $write = new ReflectionMethod($runner, 'writePrivateJson');

        $write->invoke($runner, $path, ['phase' => 'running']);
        clearstatcache(true, $path);
        $this->assertSame(['phase' => 'running'], json_decode(file_get_contents($path), true));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame(fileowner($this->directory), fileowner($path));
        $this->assertSame(filegroup($this->directory), filegroup($path));

        $write->invoke($runner, $path, ['phase' => 'ready']);
        clearstatcache(true, $path);
        $this->assertSame(['phase' => 'ready'], json_decode(file_get_contents($path), true));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame([], glob($this->directory.'/*.tmp'));
    }

    public function test_shared_temporary_directory_permissions_are_not_changed(): void
    {
        $shared = $this->directory.'/shared';
        mkdir($shared, 0777);
        chmod($shared, 0777);
        $file = $this->directory.'/report.json';
        file_put_contents($file, '{}');
        $owner = fileowner($file);

        WorkflowAcceptanceStorage::secureFile($file, $shared);

        $this->assertSame(0777, fileperms($shared) & 0777);
        $this->assertSame($owner, fileowner($file));
        $this->assertSame(0600, fileperms($file) & 0777);
    }

    public function test_symlinked_report_directory_is_rejected(): void
    {
        $link = $this->directory.'/link';
        symlink($this->directory, $link);

        $this->expectException(RuntimeException::class);
        WorkflowAcceptanceStorage::prepareDirectory($link);
    }

    public function test_symlinked_temporary_file_is_rejected_without_changing_target(): void
    {
        $target = $this->directory.'/state.json';
        file_put_contents($target, 'original');
        chmod($target, 0644);
        $link = $this->directory.'/link.json';
        symlink($target, $link);

        try {
            WorkflowAcceptanceStorage::secureFile($link, $this->directory);
            $this->fail('Symlink must be rejected');
        } catch (RuntimeException) {
            $this->assertSame('original', file_get_contents($target));
            $this->assertSame(0644, fileperms($target) & 0777);
        }
    }
}
