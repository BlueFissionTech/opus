# Testing Opus Framework

## Baseline PHP Tests

Run the default unit and feature suites:

```powershell
vendor\bin\phpunit --do-not-cache-result
```

Run the runtime contract proof tests only:

```powershell
vendor\bin\phpunit --do-not-cache-result tests\Unit\RuntimeContractProofServiceTest.php tests\Unit\RuntimeContractManagerTest.php
```

Validate the root-only GitHub VCS registry and recursive Blue Fission package
graph:

```powershell
composer audit:composer-vcs
```

The audit requires `bluefission/automata`, `bluefission/bluecore`,
`bluefission/chronicler`, `bluefission/develation`,
`bluefission/simpleclients`, and `bluefission/synthetiq` to use tagged
Packagist releases without root VCS overrides. The audit repeats any root-only
release aliases in the consumer template. All other discovered
`bluefission/*` packages must have canonical GitHub VCS routes in both the Opus
root and the consumer template.

Run Composer-installed root and entrypoint coverage with:

```powershell
vendor\bin\phpunit --do-not-cache-result tests\Unit\Business\Services\RuntimePathResolverTest.php
vendor\bin\phpunit --do-not-cache-result tests\Unit\RuntimeSettingsTest.php
vendor\bin\phpunit --do-not-cache-result tests\Unit\InstalledAddOnEntrypointTest.php
vendor\bin\phpunit --do-not-cache-result tests\Unit\TerminalBootstrapOrderTest.php
vendor\bin\phpunit --do-not-cache-result tests\Unit\Registration\AppRegistrationTest.php
```

The resolver fixtures model source checkouts and Composer source/distribution
installs. The entrypoint tests verify Composer binary metadata, active host
autoload precedence, distinct host/package constants, package-owned themes,
and clean login/administration template rendering.

## Environment Bootstrap

Process environment values take precedence over entries in the root `.env`
file. An absent or empty process value may be populated from `.env`. Web, CLI,
and worker entrypoints load this policy through the shared application settings
bootstrap.

`EnvironmentLoader::import()` returns an `Arr` report containing counts and a
per-key `process` or `dotenv` source. The report intentionally excludes all
configuration values so it can be used in startup diagnostics without exposing
secrets.

Run the focused environment and entrypoint coverage with:

```powershell
vendor\bin\phpunit --do-not-cache-result tests\Unit\Business\Services\EnvironmentLoaderTest.php
vendor\bin\phpunit --do-not-cache-result tests\Unit\TerminalBootstrapOrderTest.php
```

## Runtime Contract Validation

The default PHPUnit suite checks that the runtime contract files are present and
that the console surface reports the expected readiness state. Interpreter-backed
validation is optional because it requires Jenerator on Composer autoload.

When the interpreter is available, run:

```powershell
$env:JENERATOR_AUTOLOAD = "<composer-autoload-providing-jenerator>"
php examples\jenss\validate.php
```

Use strict mode when optional target gaps should fail the run:

```powershell
$env:JENERATOR_AUTOLOAD = "<composer-autoload-providing-jenerator>"
php examples\jenss\validate.php --strict
```

## Frontend Build Checks

Install the locked frontend graph and validate the Opus-owned manifest before
building:

```powershell
npm ci
npm run assets:validate
npm run test:assets
npm run build
npm run watch
npm run start
```

Set `OPUS_ASSET_THEME` to compile a theme other than `default`. The selected
theme must provide `src/index.js` and `assets/` beneath its markup directory.
Add-on entries are discovered from the package-owned paths documented in
`ASSETS.md`. Validation reports missing sources and entry collisions before
Webpack starts.

## Optional Services

Tests that require databases, queues, external APIs, model hosts, or secrets
must remain opt-in. Do not require optional services for the baseline suite.
