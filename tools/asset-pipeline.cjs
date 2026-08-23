const fs = require('node:fs');
const path = require('node:path');

const ENTRY_NAME_PATTERN = /^[a-z][a-z0-9-]*$/;

const CORE_ENTRIES = Object.freeze({
  app: 'resource/src/js/app.js',
  main: 'resource/src/js/pages/admin/main.js',
  'login-page': 'resource/src/js/modules/app/login-page.js',
  'module-dashboard': 'resource/src/js/modules/app/module-dashboard.js',
  'module-user': 'resource/src/js/modules/app/module-user.js',
  'module-addons': 'resource/src/js/modules/app/module-addons.js',
  'module-terminal': 'resource/src/js/modules/app/module-terminal.js',
  'module-content': 'resource/src/js/modules/app/module-content.js',
  'module-media': 'resource/src/js/modules/app/module-media.js'
});

const CORE_COPY_PATTERNS = Object.freeze([
  { from: 'resource/src/img', to: 'img' },
  { from: 'node_modules/xterm/css/xterm.css', to: 'css/xterm.css' },
  { from: 'resource/src/css/custom.css', to: 'css/custom.css' },
  { from: 'resource/src/css/admin.css', to: 'css/admin.css' }
]);

const LEGACY_EXCLUDED_ROOTS = Object.freeze([
  'resource/markup/default/js'
]);

function normalizeName(value, label) {
  const name = String(value ?? '').trim();
  if (!ENTRY_NAME_PATTERN.test(name)) {
    throw new Error(`${label} must match ${ENTRY_NAME_PATTERN}. Received: ${name || '<empty>'}`);
  }

  return name;
}

function selectedTheme(environment = process.env) {
  return normalizeName(environment.OPUS_ASSET_THEME || 'default', 'OPUS_ASSET_THEME');
}

function discoverAddOnEntries(projectRoot, reservedEntries = []) {
  const addonsRoot = path.join(projectRoot, 'addons');
  if (!fs.existsSync(addonsRoot)) {
    return {};
  }

  const entries = {};
  const reserved = new Set(reservedEntries);
  const addonNames = fs.readdirSync(addonsRoot, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && !entry.name.startsWith('.'))
    .map((entry) => entry.name)
    .sort((left, right) => left.localeCompare(right));

  for (const rawName of addonNames) {
    const addonName = normalizeName(rawName, 'Add-on directory name');
    const candidates = [
      [`module-${addonName}`, path.join(addonsRoot, addonName, 'resource', 'src', `module-${addonName}.js`)],
      [addonName, path.join(addonsRoot, addonName, 'resource', 'src', `${addonName}.js`)]
    ];

    for (const [entryName, entryPath] of candidates) {
      if (!fs.existsSync(entryPath)) {
        continue;
      }
      if (reserved.has(entryName) || Object.hasOwn(entries, entryName)) {
        throw new Error(`Add-on asset entry '${entryName}' conflicts with an existing entry.`);
      }

      entries[entryName] = entryPath;
    }
  }

  return entries;
}

function validateSources(projectRoot, entries, copyPatterns) {
  const missing = [];
  for (const [name, source] of Object.entries(entries)) {
    if (!fs.existsSync(source)) {
      missing.push(`entry '${name}': ${path.relative(projectRoot, source)}`);
    }
  }
  for (const pattern of copyPatterns) {
    if (!fs.existsSync(pattern.from)) {
      missing.push(`copy source: ${path.relative(projectRoot, pattern.from)}`);
    }
  }

  if (missing.length > 0) {
    throw new Error(
      `Asset pipeline sources are missing:\n- ${missing.join('\n- ')}\nRun npm ci before validation when a source belongs to node_modules.`
    );
  }
}

function createAssetManifest(projectRoot = process.cwd(), environment = process.env) {
  const root = path.resolve(projectRoot);
  const theme = selectedTheme(environment);
  const themeRoot = path.join(root, 'resource', 'markup', theme);
  const themeEntryName = `theme-${theme}`;
  const entries = Object.fromEntries(
    Object.entries(CORE_ENTRIES).map(([name, source]) => [name, path.join(root, source)])
  );
  entries[themeEntryName] = path.join(themeRoot, 'src', 'index.js');

  Object.assign(entries, discoverAddOnEntries(root, Object.keys(entries)));

  const copyPatterns = CORE_COPY_PATTERNS.map((pattern) => ({
    from: path.join(root, pattern.from),
    to: pattern.to
  }));
  copyPatterns.push({ from: path.join(themeRoot, 'assets'), to: '.' });

  validateSources(root, entries, copyPatterns);

  return Object.freeze({
    projectRoot: root,
    outputRoot: path.join(root, 'public', 'assets'),
    theme,
    themeRoot,
    entries: Object.freeze(entries),
    copyPatterns: Object.freeze(copyPatterns),
    legacyExcludedRoots: LEGACY_EXCLUDED_ROOTS.map((source) => path.join(root, source))
  });
}

if (require.main === module) {
  try {
    const manifest = createAssetManifest();
    process.stdout.write(
      `Asset pipeline is valid for theme '${manifest.theme}' with ${Object.keys(manifest.entries).length} entries.\n`
    );
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  }
}

module.exports = {
  CORE_COPY_PATTERNS,
  CORE_ENTRIES,
  LEGACY_EXCLUDED_ROOTS,
  createAssetManifest,
  discoverAddOnEntries,
  selectedTheme,
  validateSources
};
