import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import {execFileSync, spawnSync} from 'node:child_process';
const tool = new URL('../../../ops/deploy/reconcile-checkout.php', import.meta.url).pathname;
const guard = new URL('../../../ops/deploy/assert-clean-tree.sh', import.meta.url).pathname;

test('reconciliation refuses drift then adopts exact Git source preserving env, builds and retired work', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'clever-reconcile-test-'));
  const git = (...args) => execFileSync('git', args, {cwd: dir, stdio: 'pipe'}).toString().trim();
  const write = (p, text) => {fs.mkdirSync(path.dirname(path.join(dir,p)),{recursive:true});fs.writeFileSync(path.join(dir,p),text);};
  try {
    git('init', '-b', 'master'); git('config', 'user.email', 'fixture@example.test'); git('config', 'user.name', 'Fixture');
    write('.gitignore', '.env\n/backups/\n/build/\n');
    write('app.php', 'old\n'); write('retired.php', 'old work\n');
    git('add','.'); git('commit','-m','old'); const old = git('rev-parse','HEAD');
    write('app.php','reviewed\n'); write('ops/deploy/assert-clean-tree.sh',fs.readFileSync(guard));
    git('add','.'); git('rm','--cached','retired.php'); git('commit','-m','target'); const target = git('rev-parse','HEAD');
    git('update-ref','refs/remotes/origin/codex/reconcile-production-20261008',target);
    git('read-tree',old); git('update-ref','refs/heads/master',old,target);
    write('retired.php','uncommitted work\n');write('.env','fixture-private\n');write('build/asset.js','old generated asset\n');
    const manifest = Object.fromEntries(['.gitignore','app.php','retired.php','ops/deploy/assert-clean-tree.sh'].map(p=>[p,crypto.createHash('sha256').update(fs.readFileSync(path.join(dir,p))).digest('hex')]));
    write('backups/manifest.json',JSON.stringify(manifest));
    const args = [tool,'--target='+target,'--expected-head='+old,'--manifest='+dir+'/backups/manifest.json','--backup='+dir+'/backups/adoption'];
    const run = (...extra) => spawnSync('php',[...args,...extra],{cwd:dir,encoding:'utf8'});
    const preview = run(); assert.equal(preview.status,0,preview.stdout+preview.stderr);
    assert.equal(git('rev-parse','HEAD'),old);
    write('app.php','concurrent edit\n'); assert.notEqual(run('--apply').status,0);
    assert.equal(git('rev-parse','HEAD'),old);assert.equal(fs.existsSync(path.join(dir,'backups/adoption')),false);
    write('app.php','reviewed\n'); const result = run('--apply');
    assert.equal(result.status,0,result.stdout+result.stderr);
    assert.equal(git('rev-parse','HEAD'),target);assert.equal(git('status','--porcelain'),'');
    assert.equal(fs.readFileSync(path.join(dir,'.env'),'utf8'),'fixture-private\n');
    assert.equal(fs.readFileSync(path.join(dir,'build/asset.js'),'utf8'),'old generated asset\n');
    assert.equal(fs.readFileSync(path.join(dir,'backups/adoption/retired/retired.php'),'utf8'),'uncommitted work\n');
    assert.ok(fs.statSync(path.join(dir,'backups/adoption/source.tar.gz')).size>0);
  } finally { fs.rmSync(dir,{recursive:true,force:true}); }
});
