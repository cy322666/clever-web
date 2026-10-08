<?php
declare(strict_types=1);

// One-time adoption of a reviewed, published Git commit. Not a regular deploy.
// No reset --hard, clean, source patches, secret files or database operations.
$options = getopt('', ['target:', 'expected-head:', 'manifest:', 'backup:', 'apply']);
foreach (['target', 'expected-head', 'manifest', 'backup'] as $required) {
    if (!isset($options[$required])) throw new RuntimeException('Missing --'.$required);
}
function run(array $args, ?string $input = null): string {
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start command.');
    fwrite($pipes[0], $input ?? ''); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException(implode(' ', array_slice($args, 0, 2)).': '.$error);
    return $out;
}
function git(array $args): string { return run(['git', ...$args]); }
function safePath(string $path): bool {
    return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '..') && !str_contains($path, "\0")
        && !preg_match('~^(?:\.git/|backups/|application/(?:storage|vendor|node_modules|public/build|public/storage|bootstrap/workflow-runtime)/)~', $path)
        && !preg_match('~(?:^|/)(?:auth\.json|\.env(?:\.[^/]*)?)$~', $path)
        && !preg_match('~(?:^|/)(?:\._[^/]*|\.DS_Store)$~', $path);
}
function placeholder(string $path): bool {
    return (str_starts_with($path, 'application/storage/') || str_starts_with($path, 'application/bootstrap/cache/'))
        && str_ends_with($path, '/.gitignore');
}
$root = trim(git(['rev-parse', '--show-toplevel']));
if (realpath(getcwd()) !== realpath($root)) throw new RuntimeException('Run at the repository root.');
$target = (string) $options['target'];
$expectedHead = (string) $options['expected-head'];
if (!preg_match('/^[a-f0-9]{40}$/', $target) || !preg_match('/^[a-f0-9]{40}$/', $expectedHead)) throw new RuntimeException('Use full commit SHAs.');
if (trim(git(['rev-parse', 'HEAD'])) !== $expectedHead) throw new RuntimeException('HEAD changed since capture.');
$branch = trim(git(['symbolic-ref', 'HEAD']));
if ($branch !== 'refs/heads/master') throw new RuntimeException('Only the primary master checkout can be adopted.');
if (trim(git(['rev-parse', 'refs/remotes/origin/codex/reconcile-production-20261008'])) !== $target) throw new RuntimeException('Target does not match the fetched reconciliation branch.');
$manifest = json_decode(file_get_contents((string) $options['manifest']), true, flags: JSON_THROW_ON_ERROR);
if (!is_array($manifest) || count($manifest) < 1) throw new RuntimeException('Empty source capture.');
foreach ($manifest as $file => $hash) {
    if ((!safePath($file) && !str_ends_with($file, '.example')) || !preg_match('/^[a-f0-9]{64}$/', $hash)) throw new RuntimeException('Unsafe capture entry.');
    if (!is_file($file) || is_link($file) || hash_file('sha256', $file) !== $hash) throw new RuntimeException('Source changed after capture: '.$file);
}
$paths = array_values(array_filter(explode("\0", git(['ls-tree', '-rz', '--name-only', $target]))));
foreach ($paths as $file) {
    if (!safePath($file) && !placeholder($file) && !str_ends_with($file, '.example')) throw new RuntimeException('Unsafe target entry: '.$file);
    if (is_link($file)) throw new RuntimeException('Refusing symlink target: '.$file);
    if (is_file($file) && !isset($manifest[$file]) && !placeholder($file)) throw new RuntimeException('Uncaptured existing target: '.$file);
}
$retired = array_values(array_diff(array_keys($manifest), $paths));
$backupInput = (string) $options['backup'];
$backupParent = realpath(dirname($backupInput));
if ($backupParent === false) throw new RuntimeException('Backup parent must already exist.');
$backup = $backupParent.'/'.basename($backupInput);
if (file_exists($backup) || !str_starts_with($backup, $root.'/backups/')) throw new RuntimeException('Use a fresh backup directory inside this repository.');
echo json_encode(['target' => $target, 'captured_files' => count($manifest), 'target_files' => count($paths), 'retired_source_files' => count($retired), 'apply' => isset($options['apply'])]).PHP_EOL;
if (!isset($options['apply'])) exit(0);

// Re-check after acquiring the shared reconciliation lock.
$gitDir = trim(git(['rev-parse', '--absolute-git-dir']));
$lock = fopen($gitDir.'/source-reconciliation.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Reconciliation already running.');
if (trim(git(['rev-parse', 'HEAD'])) !== $expectedHead) throw new RuntimeException('HEAD changed.');
foreach ($manifest as $file => $hash) if (hash_file('sha256', $file) !== $hash) throw new RuntimeException('Source changed: '.$file);
mkdir($backup, 0700, true);
file_put_contents($backup.'/head', $expectedHead."\n");
file_put_contents($backup.'/status', git(['status', '--porcelain=v1', '--untracked-files=all']));
file_put_contents($backup.'/changes.patch', git(['diff', '--binary', 'HEAD']));
copy($gitDir.'/index', $backup.'/index');
file_put_contents($backup.'/source.paths', implode("\0", array_keys($manifest))."\0");
run(['tar', '--null', '-czf', $backup.'/source.tar.gz', '-T', $backup.'/source.paths']);
$assets = array_values(array_filter(['application/public/build', 'application/bootstrap/workflow-runtime'], 'is_dir'));
if ($assets !== []) run(['tar', '-czf', $backup.'/assets.tar.gz', ...$assets]);
git(['branch', 'codex/pre-reconcile-'.substr($target, 0, 12), $expectedHead]);

// Every source write comes from the already-published target commit.
file_put_contents($backup.'/target.paths', implode("\0", $paths)."\0");
git(['restore', '--source='.$target, '--worktree', '--pathspec-from-file='.$backup.'/target.paths', '--pathspec-file-nul']);
foreach ($retired as $file) {
    if (!file_exists($file)) continue;
    // Old generated archives remain available; obsolete source is preserved away
    // from application discovery. The target .gitignore is already installed.
    try { git(['check-ignore', '--no-index', '--', $file]); continue; } catch (RuntimeException) {}
    $to = $backup.'/retired/'.$file;
    if (!is_dir(dirname($to))) mkdir(dirname($to), 0700, true);
    if (!rename($file, $to)) throw new RuntimeException('Cannot preserve retired source: '.$file);
}
git(['read-tree', $target]);
git(['update-ref', $branch, $target, $expectedHead]);
foreach (explode("\0", git(['ls-tree', '-rz', $target])) as $entry) {
    if ($entry === '') continue;
    [$meta, $file] = explode("\t", $entry, 2);
    $blob = explode(' ', $meta)[2];
    if (trim(git(['hash-object', '--', $file])) !== $blob) throw new RuntimeException('File does not match commit: '.$file);
}
echo run(['bash', 'ops/deploy/assert-clean-tree.sh', $target]);
echo 'RECONCILED_FROM_GIT '.$target.PHP_EOL;
