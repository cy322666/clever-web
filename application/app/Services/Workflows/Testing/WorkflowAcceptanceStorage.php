<?php

namespace App\Services\Workflows\Testing;

use RuntimeException;

final class WorkflowAcceptanceStorage
{
    public static function prepareDirectory(string $directory): void
    {
        if ($directory === '' || is_link($directory)) {
            throw new RuntimeException('Unsafe acceptance report directory');
        }

        if (! is_dir($directory)) {
            $parent = dirname($directory);
            if (! is_dir($parent)) {
                self::prepareDirectory($parent);
            }
            if (! @mkdir($directory, 0700) && ! is_dir($directory)) {
                throw new RuntimeException('Cannot create private acceptance report directory');
            }
            self::inheritOwner($directory, $parent);
        }

        if (! @chmod($directory, 0700)) {
            throw new RuntimeException('Cannot secure acceptance report directory');
        }
    }

    public static function secureFile(string $path, string $directory): void
    {
        if (is_link($path) || ! @chmod($path, 0600)) {
            throw new RuntimeException('Cannot secure acceptance report file');
        }

        // Atomic replacement must keep the service owner, even after a manual root run.
        self::inheritOwner($path, $directory);
    }

    private static function inheritOwner(string $path, string $directory): void
    {
        clearstatcache(true, $directory);
        $permissions = @fileperms($directory);
        $owner = @fileowner($directory);
        $group = @filegroup($directory);
        if ($permissions === false || $owner === false || $group === false || is_link($directory)) {
            throw new RuntimeException('Cannot determine acceptance directory ownership');
        }

        // Shared temporary directories do not define the owner of their users' files.
        if (($permissions & 0002) !== 0) {
            return;
        }

        clearstatcache(true, $path);
        if (fileowner($path) !== $owner && ! @chown($path, $owner)) {
            throw new RuntimeException('Cannot preserve acceptance file owner; run as the directory owner');
        }
        if (filegroup($path) !== $group && ! @chgrp($path, $group)) {
            throw new RuntimeException('Cannot preserve acceptance file group; run as the directory owner');
        }
        clearstatcache(true, $path);
    }
}
