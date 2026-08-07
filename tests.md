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

The audit intentionally permits `bluefission/develation` without a VCS entry;
all other discovered `bluefission/*` packages must have canonical GitHub routes
in both the Opus root and the consumer template.

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

The current frontend scripts are:

```powershell
npm run build
npm run watch
npm run start
```

Webpack cleanup is tracked as roadmap work. Until that work lands, build
validation should report missing asset paths, legacy asset assumptions, or
template metadata drift instead of hiding them.

## Optional Services

Tests that require databases, queues, external APIs, model hosts, or secrets
must remain opt-in. Do not require optional services for the baseline suite.
