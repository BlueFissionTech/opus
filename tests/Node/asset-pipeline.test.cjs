const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const {
  CORE_COPY_PATTERNS,
  CORE_ENTRIES,
  LEGACY_EXCLUDED_ROOTS,
  createAssetManifest,
  discoverAddOnEntries,
  selectedTheme
} = require('../../tools/asset-pipeline.cjs');

function createFile(root, relativePath, contents = '') {
  const target = path.join(root, relativePath);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(target, contents);
}

function createWorkspace() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'opus-assets-'));
  for (const source of Object.values(CORE_ENTRIES)) {
    createFile(root, source);
  }
  for (const pattern of CORE_COPY_PATTERNS) {
    createFile(root, pattern.from);
  }
  createFile(root, 'resource/markup/default/src/index.js');
  createFile(root, 'resource/markup/default/assets/.keep');

  return root;
}

test('builds a deterministic manifest for core, theme, and add-on entries', (context) => {
  const root = createWorkspace();
  context.after(() => fs.rmSync(root, { recursive: true, force: true }));
  createFile(root, 'addons/zeta/resource/src/zeta.js');
  createFile(root, 'addons/alpha/resource/src/module-alpha.js');

  const manifest = createAssetManifest(root, {});

  assert.equal(manifest.theme, 'default');
  assert.equal(manifest.entries['theme-default'], path.join(root, 'resource/markup/default/src/index.js'));
  assert.deepEqual(Object.keys(manifest.entries).slice(-2), ['module-alpha', 'zeta']);
  assert.equal(manifest.outputRoot, path.join(root, 'public/assets'));
});

test('rejects unsafe or unavailable theme selections', (context) => {
  const root = createWorkspace();
  context.after(() => fs.rmSync(root, { recursive: true, force: true }));

  assert.throws(() => selectedTheme({ OPUS_ASSET_THEME: '../admin' }), /must match/);
  assert.throws(
    () => createAssetManifest(root, { OPUS_ASSET_THEME: 'missing' }),
    /theme-missing|resource.*markup.*missing/i
  );
});

test('rejects add-on entry collisions and unsafe directory names', (context) => {
  const root = createWorkspace();
  context.after(() => fs.rmSync(root, { recursive: true, force: true }));
  createFile(root, 'addons/app/resource/src/app.js');

  assert.throws(
    () => discoverAddOnEntries(root, Object.keys(CORE_ENTRIES)),
    /conflicts with an existing entry/
  );

  fs.rmSync(path.join(root, 'addons/app'), { recursive: true, force: true });
  createFile(root, 'addons/Bad_Name/resource/src/Bad_Name.js');
  assert.throws(() => discoverAddOnEntries(root), /Add-on directory name must match/);
});

test('reports every missing entry and copy source before Webpack starts', (context) => {
  const root = createWorkspace();
  context.after(() => fs.rmSync(root, { recursive: true, force: true }));
  fs.rmSync(path.join(root, 'resource/src/js/app.js'));
  fs.rmSync(path.join(root, 'resource/src/css/admin.css'));

  assert.throws(
    () => createAssetManifest(root, {}),
    (error) => error.message.includes("entry 'app'")
      && error.message.includes(path.join('resource', 'src', 'css', 'admin.css'))
      && error.message.includes('npm ci')
  );
});

test('keeps duplicate legacy script roots outside production copy patterns', (context) => {
  const root = createWorkspace();
  context.after(() => fs.rmSync(root, { recursive: true, force: true }));
  const manifest = createAssetManifest(root, {});
  const copiedRoots = manifest.copyPatterns.map((pattern) => pattern.from);

  for (const legacyRoot of LEGACY_EXCLUDED_ROOTS) {
    assert.equal(copiedRoots.includes(path.join(root, legacyRoot)), false);
  }
});
