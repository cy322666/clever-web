<?php

declare(strict_types=1);

// Run without Composer: the autoloader itself may be unreadable after a deploy.
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "Run this check as the application user, not root.\n");
    exit(1);
}

$base = rtrim($argv[1] ?? dirname(__DIR__), '/');
$directories = ['app', 'bootstrap', 'config', 'routes', 'resources/views', 'vendor'];
$requiredFiles = ['bootstrap/app.php', 'public/index.php', 'vendor/autoload.php'];
$errors = [];
$checked = 0;
$visited = [];

foreach ($requiredFiles as $file) {
    if (!is_file($base.'/'.$file) || !is_readable($base.'/'.$file)) {
        $errors[] = "Unreadable entrypoint: {$file}";
    }
}

$pending = $directories;
while ($pending !== []) {
    $relative = array_pop($pending);
    $directory = $base.'/'.$relative;
    if (!is_dir($directory) || !is_readable($directory) || !is_executable($directory)) {
        $errors[] = "Unreadable or unsearchable directory: {$relative}";
        continue;
    }

    $real = realpath($directory);
    if (isset($visited[$real])) {
        continue;
    }
    $visited[$real] = true;

    $entries = @scandir($directory);
    if ($entries === false) {
        $errors[] = "Cannot list directory: {$relative}";
        continue;
    }

    foreach ($entries as $entry) {
        if (str_starts_with($entry, '.')) {
            continue;
        }
        $child = $relative.'/'.$entry;
        $file = $base.'/'.$child;
        if (is_dir($file)) {
            $pending[] = $child;
        } elseif (in_array(pathinfo($entry, PATHINFO_EXTENSION), ['php', 'json', 'js', 'mjs'], true)) {
            $checked++;
            if (!is_file($file) || !is_readable($file)) {
                $errors[] = "Unreadable runtime file: {$child}";
            }
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error."\n");
    }
    exit(1);
}

echo "Runtime access check passed ({$checked} files).\n";
