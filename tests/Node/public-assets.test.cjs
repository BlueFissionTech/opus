const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const test = require('node:test');
const { isPrivateAsset, auditTrackedFiles } = require('../../tools/audit-public-assets.cjs');

const project = path.resolve(__dirname, '../..');
const privatePaths = [
  'snow/index.txt', 'Snow-Tailwind/src/input.txt', 'SNOW.PHP',
  'resource/markup/snow/index.txt', 'addons/example/Snow-Laravel/input.txt',
  'public/assets/snow.css', 'nested/private-assets/bundle.txt', 'private-assets/bundle.txt'
];

function run(command, args, cwd) {
  const result = spawnSync(command, args, { cwd, encoding: 'utf8', timeout: 60000 });
  assert.equal(result.status, 0, `${command}: ${result.stderr}`);
  return result.stdout;
}

function fixture(context) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'opus-public-assets-'));
  context.after(() => fs.rmSync(root, { recursive: true, force: true }));
  run('git', ['init', '--quiet'], root);
  for (const name of ['.gitignore', '.gitattributes', '.npmignore', '.dockerignore']) {
    fs.copyFileSync(path.join(project, name), path.join(root, name));
  }
  for (const name of [...privatePaths, 'resource/markup/default/index.vibe', 'README.md']) {
    fs.mkdirSync(path.dirname(path.join(root, name)), { recursive: true });
    fs.writeFileSync(path.join(root, name), 'Synthetic packaging sentinel only.\n');
  }
  fs.writeFileSync(path.join(root, 'package.json'), JSON.stringify({ name: 'opus-synthetic-packaging', version: '1.0.0', private: true }));
  return root;
}

test('reserves private path segments without classifying unrelated words or documentation', () => {
  for (const file of privatePaths) assert.equal(isPrivateAsset(file), true, file);
  assert.equal(isPrivateAsset('resource\\Snow-PHP\\index.php'), true);
  for (const file of ['docs/private-theme-assets.md', 'snowflake.txt', 'resource/markup/default/index.vibe']) {
    assert.equal(isPrivateAsset(file), false, file);
  }
});

test('normal staging excludes private inputs; forced staging fails the public audit without exposing paths', (context) => {
  const root = fixture(context);
  run('git', ['add', '.'], root);
  assert.equal(auditTrackedFiles(root).ready, true);
  for (const file of privatePaths) {
    run('git', ['check-ignore', '--no-index', file], root);
    run('git', ['add', '--force', '--', file], root);
  }
  const report = auditTrackedFiles(root);
  assert.deepEqual(report, { ready: false, reason: 'private_assets_tracked', count: privatePaths.length });
  const cli = spawnSync(process.execPath, [path.join(project, 'tools/audit-public-assets.cjs'), root], { encoding: 'utf8' });
  assert.equal(cli.status, 1);
  assert.deepEqual(JSON.parse(cli.stdout), report);
  assert.equal(cli.stdout.includes(root), false);
});

test('source archives exclude even force-committed private inputs and retain public templates', (context) => {
  const root = fixture(context);
  run('git', ['add', '--force', '.'], root);
  run('git', ['-c', 'user.name=Packaging Test', '-c', 'user.email=packaging@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Synthetic fixture'], root);
  const archive = path.join(root, 'source.tar');
  run('git', ['archive', '--format=tar', `--output=${archive}`, 'HEAD'], root);
  const entries = run('tar', ['-tf', archive], root).trim().split(/\r?\n/);
  assert.equal(entries.some(isPrivateAsset), false);
  assert.ok(entries.includes('resource/markup/default/index.vibe'));
});

test('npm package listing excludes private files without running package scripts', (context) => {
  const root = fixture(context);
  // npm's installed CLI is portable; avoid shell interpolation on Windows.
  const npm = process.env.npm_execpath;
  assert.ok(npm, 'Run through npm run test:assets so the installed npm CLI is explicit.');
  const listing = JSON.parse(run(process.execPath, [npm, 'pack', '--dry-run', '--json', '--ignore-scripts'], root));
  const entries = listing[0].files.map((file) => file.path);
  assert.equal(entries.some(isPrivateAsset), false);
  assert.ok(entries.includes('resource/markup/default/index.vibe'));
});

test('inventory errors fail closed and the current tracked source is clear', (context) => {
  const empty = fs.mkdtempSync(path.join(os.tmpdir(), 'opus-no-inventory-'));
  context.after(() => fs.rmSync(empty, { recursive: true, force: true }));
  assert.throws(() => auditTrackedFiles(empty), /public_asset_inventory_unavailable/);
  assert.equal(auditTrackedFiles(project).ready, true);
});

test('Composer archives independently exclude private paths and retain fallback templates', (context) => {
  const root = fixture(context);
  // Remove other exclusion mechanisms so only Composer's own rules prove this boundary.
  fs.unlinkSync(path.join(root, '.gitignore'));
  fs.unlinkSync(path.join(root, '.gitattributes'));
  const policy = JSON.parse(fs.readFileSync(path.join(project, 'composer.json'), 'utf8')).archive;
  fs.writeFileSync(path.join(root, 'composer.json'), JSON.stringify({
    name: 'opus/synthetic-packaging', version: '1.0.0', license: 'MIT', archive: policy
  }));
  const result = spawnSync('composer', [
    'archive', '--format=tar', '--dir=dist', '--no-plugins', '--no-scripts', '--no-interaction'
  ], {
    cwd: root, encoding: 'utf8', timeout: 60000,
    // Windows Composer is a .bat launcher. All command arguments above are literals.
    shell: process.platform === 'win32'
  });
  assert.equal(result.status, 0, result.stderr);
  const archives = fs.readdirSync(path.join(root, 'dist')).filter((file) => file.endsWith('.tar'));
  assert.equal(archives.length, 1);
  const entries = run('tar', ['-tf', path.join(root, 'dist', archives[0])], root).trim().split(/\r?\n/);
  assert.equal(entries.some(isPrivateAsset), false, JSON.stringify(entries));
  assert.ok(entries.includes('resource/markup/default/index.vibe'));
});
