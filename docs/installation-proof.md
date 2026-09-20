# Installed Dependency Proof

Before booting an installation, compare its Composer metadata to the selected
lockfile:

```sh
php bin/audit-installed-dependencies.php
php bin/audit-installed-dependencies.php --no-dev
```

Use the first command after a development install and the second after an
explicit production `composer install --no-dev`. The production check rejects
unexpected packages, including development dependencies. The command reads the
lockfile and `vendor/composer/installed.json` relative to its own project root,
independently of the current directory. Custom vendor directories are not yet
supported; missing metadata is an error, never a clean bill of health.

This diagnostic deliberately runs without Composer autoload or application
bootstrap. It uses native PHP at the tooling boundary so broken or mismatched
DevElation/runtime dependencies cannot prevent the report. It does not load
environment files, invoke providers, run package scripts, modify dependencies,
or bootstrap add-ons. JSON output contains package identities and references,
never repository URLs or credentials. Exit codes are `0` (matching metadata),
`1` (drift), and `2` (invalid/unavailable metadata or unsupported arguments).

The version and both source/dist references must match; missing and extra
packages are reported together in stable order. Composer 1 package lists and
Composer 2 installed metadata are supported. A pass is metadata consistency,
not file-integrity, dirty-checkout, security, compatibility or runtime proof.
Preserve local dependency edits before restoration; never use this command's
report as permission to overwrite them.

## Installation Sprint Acceptance

1. Validate manifest/lock consistency and platform requirements using Composer.
2. Install the exact lock in a clean disposable destination using the reviewed
   script/plugin policy; retain source revision and lock hash.
3. Run this diagnostic with the matching development/production mode.
4. Run native command, configuration, lifecycle and tenant/permission tests.
5. Exercise install/configure/upgrade/recovery with synthetic fixtures; record
   unavailable optional features explicitly.
6. Publish reproducible commands, redacted evidence and a rollback path with
   the release. A failed or unattempted step blocks the corresponding claim.

The focused metadata tests can run using an existing PHPUnit installation:
`php vendor/phpunit/phpunit/phpunit tests/Unit/InstalledDependencyAuditTest.php`.
They require no network, provider credentials, database or additional packages.

## Recorded Development Baseline

The [2026-09-19 locked runtime proof](locked-runtime-proof-2026-09-19.md) records
an exact-lock source-root run, including machine-readable package references,
passing contracts, skipped coverage and upstream warnings. It is evidence for
that revision and runtime only; rerun the gates above before making a release
or consumer-installation claim.
