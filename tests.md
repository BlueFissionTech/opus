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


## Strict lifecycle readiness results

Run `php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter
AddOnLifecycleReadinessServiceTest` (put the command on one line).
The suite checks missing and mistyped success flags, invalid hook and batch
collections, malformed datasource outcomes, partial changes, accurate batch
counts, and compatibility with optional missing lifecycle hooks. Also run
`--filter AddOnManagerTest` for the console adapter, then the full PHPUnit suite.
These fixtures use no credentials, database, provider, or network services.

## Installed dependency consistency

Before diagnosing runtime failures, run `php bin/audit-installed-dependencies.php`.
For a production-only install, add `--no-dev`. This pre-autoload JSON diagnostic
compares actual Composer metadata to the lock without booting optional services.
See [installation proof](docs/installation-proof.md) for exit codes and limits.
Focused check: `php vendor/phpunit/phpunit/phpunit tests/Unit/InstalledDependencyAuditTest.php`.
No secrets, provider calls, database, or new dependency installation are required.

## Command-context lifecycle hooks

Run `php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter AgentCommandContextLifecycleTest`.
The fixtures prove that context filters cannot fabricate or erase queried
activation, recover an unavailable lifecycle query, revive revoked add-ons on
continuation, or unlock inactive specialist tools. Active specialist tools and
benign metadata remain available. No provider, database, or credential is used.
Also run `--filter 'AgentCapabilityMapTest|AgentScopedProfileTest|ExtensionPointCatalogTest'`
and the full PHPUnit suite for integration coverage.

The lifecycle context suite also covers a valid record followed by a malformed
record, including continuation refresh and a filter attempting to forge state.
Both activation fields must be empty after the normalization failure.

## Consulting guidance

Run `php vendor/phpunit/phpunit/phpunit --filter "ConsultingGuidanceServiceTest|AppRegistrationTest" --do-not-cache-result`
for the optional advisory service contracts. These tests use deterministic local
providers and require no credentials or external service.

## Exact-lock runtime baseline

The [recorded development proof](docs/locked-runtime-proof-2026-09-19.md) includes
reproducible commands, package references and all skipped test identifiers.
Run against the installation's own Composer autoloader and PHPUnit. Preserve
JUnit output and shutdown warnings alongside the exit status; dependency
metadata consistency alone does not establish runtime or release readiness.

## Composer metadata quality gate

Run `composer validate:composer` before publishing package metadata. This invokes
strict manifest/lock validation with plugins disabled and does not install or
update dependencies. Opus declares `Apache-2.0`; the complete license is shipped
in `LICENSE`. Dependency and private-asset licenses remain separate.


## Required baseline gates

Run `composer lint:php`, `composer test`, `node --check webpack.config.js`,
`node --check tools/asset-pipeline.cjs`, and `npm run test:assets` before release.
The `Baseline quality` workflow runs on pushes and pull requests, using PHP 8.2
on Linux and Windows plus a Node frontend configuration job. Syntax runs before
Composer installation and compiles every tracked PHP working file, including
files PHPUnit does not autoload. New PHP files must be staged to enter the gate.
The gate exits nonzero on unreadable/missing tracked files or any compile error.
Its regression fixture proves both valid and invalid compiler outcomes without
executing the checked source.

CI installs the committed Composer lock with application scripts disabled. It
runs the declared Feature and Unit suites under PHPUnit 9.6; the empty Feature
suite directory is retained in Git. Existing command, readiness, and lifecycle
contract tests run with the baseline. Optional network/service tests remain
opt-in; this workflow supplies no API keys or service credentials.

The frontend gate tests manifest behavior and build-script syntax without an npm
installation. It does not claim to compile a production bundle or supply private
licensed theme assets. A deployable host must also run `npm run build` with its
chosen assets as described in the asset ownership documentation. Repository
administrators must require these workflow checks in branch/release protection
if they want GitHub to prevent bypassing a failed check.

Filesystem capability probes handle only known unsupported link warnings within
the fixture-creation call. They restore the prior handler, leave genuine errors
visible, and never suppress the operation being tested. No vendor error handler
is patched.

## Dynamic processing

`DynamicProcessor` accepts an array or plain object containing `rules`, or a
`logic_id` with an explicitly injected rule-loader callback. There is no implicit
database table or model. The loader owns authorization and persistence. A rule
list contains 1–100 records, with `command`, optional `id`, and optional boolean
`use_input`. Other keys fail explicitly, including legacy `function`, `api`,
`parent_id`, and condition definitions from the previously incomplete handler.
Migrate such operations to registered, authorized commands; orchestration owns
conditional selection.

```php
$handler = new \App\Business\Processors\LogicHandler($commandProcessor, $trustedContext);
$processor = new \App\Business\Processors\DynamicProcessor([
    'rules' => [[
        'command' => ['verb' => 'create', 'resources' => ['note'], 'args' => []],
        'use_input' => true,
    ]],
], $handler);
$result = $processor->execute('A note title');
```

The default handler lazily uses `LazyAgentCommandProcessor`; an injected processor
must retain the same host authorization contract. Context comes from the host,
never rule metadata. Structured commands require a nonempty verb, nonempty list
of resource names, and optional list of scalar/null arguments. `use_input`
appends scalar/null input as one argument without parsing it as command text.
Every rule receives the original input. All record shapes and input bindings
are checked before the first dispatch; command meaning and authority are checked
by the shared processor at dispatch. Only `completed` advances to the next rule.
Pending approval, invalid, failed, or parse-only results stop the sequence and
are returned unchanged. Completed effects are not rolled back, and rerunning a
sequence may repeat them; approval continuation belongs to the shared command
surface, not automatic sequence resumption.
