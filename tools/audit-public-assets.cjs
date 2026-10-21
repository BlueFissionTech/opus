const path = require('node:path');
const { spawnSync } = require('node:child_process');

// This reserves named inputs and outputs, not arbitrary licensed file contents.
const PRIVATE_SEGMENT = /^(?:private-assets|snow(?:[-_.].*)?)$/i;

function isPrivateAsset(relativePath) {
  return relativePath.split(/[\\/]/).some((segment) => PRIVATE_SEGMENT.test(segment));
}

function auditTrackedFiles(root) {
  const result = spawnSync('git', ['ls-files', '--cached', '-z'], {
    cwd: root,
    encoding: 'utf8',
    maxBuffer: 32 * 1024 * 1024,
    timeout: 30000
  });
  if (result.error || result.status !== 0) {
    throw new Error('public_asset_inventory_unavailable');
  }
  const count = result.stdout.split('\0').filter((file) => file && isPrivateAsset(file)).length;
  return { ready: count === 0, reason: count ? 'private_assets_tracked' : 'public_asset_paths_clear', count };
}

if (require.main === module) {
  try {
    const report = auditTrackedFiles(path.resolve(process.argv[2] || path.join(__dirname, '..')));
    console.log(JSON.stringify(report));
    process.exitCode = report.ready ? 0 : 1;
  } catch {
    console.error(JSON.stringify({ ready: false, reason: 'public_asset_inventory_unavailable' }));
    process.exitCode = 1;
  }
}

module.exports = { isPrivateAsset, auditTrackedFiles };
