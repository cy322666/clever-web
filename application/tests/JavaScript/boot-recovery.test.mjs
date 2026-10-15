import test from 'node:test';
import assert from 'node:assert/strict';
import { chmodSync, existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const recoveryScript = resolve(import.meta.dirname, '../../../ops/systemd/boot-recover.sh');

function runRecovery(alreadyHealthy) {
    const directory = mkdtempSync(resolve(tmpdir(), 'clever-boot-recovery-'));
    const docker = resolve(directory, 'docker');
    const curl = resolve(directory, 'curl');
    const ready = resolve(directory, 'ready');
    const log = resolve(directory, 'docker.log');

    writeFileSync(docker, `#!/bin/sh
printf '%s\\n' "$*" >> "$BOOT_TEST_LOG"
case "$*" in
  *' ps -q '*) printf 'test-container\\n' ;;
  'inspect -f '*' test-container') printf 'true\\n' ;;
  *' up -d --no-build postgresql redis supervisor app') touch "$BOOT_TEST_READY" ;;
esac
`);
    writeFileSync(curl, `#!/bin/sh
test -f "$BOOT_TEST_READY" && printf OK
`);
    chmodSync(docker, 0o755);
    chmodSync(curl, 0o755);
    if (alreadyHealthy) writeFileSync(ready, '');

    try {
        const result = spawnSync('sh', [recoveryScript], {
            encoding: 'utf8',
            env: {
                ...process.env,
                CLEVER_BOOT_PROJECT_DIR: directory,
                CLEVER_BOOT_DOCKER: docker,
                CLEVER_BOOT_CURL: curl,
                CLEVER_BOOT_CHECKS: '2',
                BOOT_TEST_READY: ready,
                BOOT_TEST_LOG: log,
            },
        });
        return { result, commands: existsSync(log) ? readFileSync(log, 'utf8') : '' };
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
}

test('boot recovery leaves a healthy stack untouched', () => {
    const { result, commands } = runRecovery(true);
    assert.equal(result.status, 0, result.stderr);
    assert.doesNotMatch(commands, / up -d /);
});

test('boot recovery starts an unhealthy stack through docker compose', () => {
    const { result, commands } = runRecovery(false);
    assert.equal(result.status, 0, result.stderr);
    assert.match(commands, /compose -f docker-compose\.yml -f monitoring\/docker-compose\.monitoring\.prod\.yml up -d --no-build postgresql redis supervisor app/);
});
