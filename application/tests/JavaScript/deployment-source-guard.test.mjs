import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync, spawnSync} from 'node:child_process';
const guard = new URL('../../../ops/deploy/assert-clean-tree.sh', import.meta.url).pathname;

test('deployment rejects unstaged, staged and untracked source and wrong SHA', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'clever-deploy-guard-'));
  const git = (...args) => execFileSync('git', args, {cwd: dir, stdio: 'pipe'}).toString().trim();
  const check = (...args) => spawnSync('bash', [guard, ...args], {cwd: dir, encoding: 'utf8'});
  try {
    git('init'); git('config', 'user.email', 'fixture@example.test'); git('config', 'user.name', 'Fixture');
    fs.writeFileSync(path.join(dir, 'app.php'), '<?php // committed\n');
    git('add', 'app.php'); git('commit', '-m', 'fixture');
    const sha = git('rev-parse', 'HEAD');
    assert.equal(check(sha).status, 0);
    assert.notEqual(check('0'.repeat(40)).status, 0);
    fs.appendFileSync(path.join(dir, 'app.php'), '// outside Git\n');
    assert.notEqual(check().status, 0);
    git('add', 'app.php');
    assert.notEqual(check().status, 0);
    git('commit', '-m', 'save');
    fs.writeFileSync(path.join(dir, 'new.php'), '<?php');
    assert.notEqual(check().status, 0);
    git('add', 'new.php'); git('commit', '-m', 'save new');
    assert.equal(check().status, 0);
  } finally {
    fs.rmSync(dir, {recursive: true, force: true});
  }
});
