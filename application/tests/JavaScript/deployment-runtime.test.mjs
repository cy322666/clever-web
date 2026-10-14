import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {spawnSync} from 'node:child_process';

const workflow = readFileSync(new URL('../../../.github/workflows/deploy-prod.yml', import.meta.url), 'utf8');
const forward = workflow.slice(workflow.indexOf('            git checkout -B "$DEPLOY_BRANCH" "$DEPLOY_SHA"'));

test('deployment checks the real app user and gracefully reloads Horizon in its own container', () => {
    for (const command of ['optimize:clear', 'migrate --force', 'optimize', 'app:smoke --strict']) {
        assert.ok(forward.includes(`compose_cmd exec -T --user www-data app php artisan ${command}`), command);
    }
    assert.ok(forward.includes('compose_cmd exec -T --user www-data supervisor php artisan horizon:terminate'));
    assert.ok(forward.includes('compose_cmd exec -T --user www-data supervisor php artisan horizon:status'));
    assert.ok(forward.indexOf('php artisan optimize\n') < forward.indexOf('php artisan horizon:terminate'));
    assert.ok(forward.indexOf('php artisan horizon:terminate') < forward.indexOf('php artisan app:smoke --strict'));
});

test('exhausted post-deploy checks invoke rollback rather than bypassing the ERR trap', () => {
    const start = workflow.indexOf('            smoke_ready=false');
    const end = workflow.indexOf('            bash ops/deploy/assert-clean-tree.sh "$DEPLOY_SHA"', start);
    assert.ok(start > 0 && end > start);
    const check = workflow.slice(start, end);
    for (const succeeds of [true, false]) {
        const result = spawnSync('bash', ['-c', `
set -Eeuo pipefail
trap 'echo rollback-invoked; exit 23' ERR
compose_cmd() { ${succeeds ? 'return 0' : 'return 1'}; }
sleep() { :; }
${check}
echo checks-passed
`], {encoding: 'utf8'});
        assert.equal(result.status, succeeds ? 0 : 23, result.stdout + result.stderr);
        assert.equal(result.stdout.includes('rollback-invoked'), !succeeds);
        assert.equal(result.stdout.includes('checks-passed'), succeeds);
    }
});
