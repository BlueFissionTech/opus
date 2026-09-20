# Locked development runtime proof — 2026-09-19

The source-root development runtime at `665d4736d6a274ba577dc0c1b861e21777aa7509`
passes its baseline suite with the exact committed dependencies. This is a
bounded installation/conformance increment for #104 and #107, not a release
certification or proof of every upstream capability.

The [machine-readable snapshot](locked-runtime-proof-2026-09-19.json) records all
94 package versions/references, commands, skipped test identifiers and limits.
Its lock SHA-256 is
`096d9266576654b1125490f9b6126e0170fab83877d904a40c55ca0d15f56217`.
Evidence applies to the named source revision; rerun it after source, lock,
platform, plugin-policy or runtime-profile changes.

## Observed results

| Check | Result |
| --- | --- |
| Fresh exact-lock development install | Exit 0; 94 installs; two upstream warnings described below |
| Installed metadata audit | 94 expected, 94 installed; no differences |
| Platform requirements | Pass on Windows, PHP 8.2.11; Composer 2.6.5 |
| Template helper, Wise host/resource, Composer contracts | 14 tests, 74 assertions; pass |
| Full PHPUnit 9.6.35 suite | 428 tests, 1,949 assertions, zero errors/failures, 11 skipped |

The previous mismatched dependency baseline's five errors and one failure do
not reproduce on this lock. No vendor patch, private autoload shim, environment
file, database, external inference provider or package script was used in this
proof. The configured Composer installer plugins were enabled. Existing
installations were preserved; this was a fresh destination with its own vendor
and add-on directories.

## Reproduce

Start from a fresh checkout of the recorded revision. Review the plugin policy
and installation permissions before running Composer. Retain the complete
command output and exit codes; an exit code alone does not establish readiness.
Run from the project root:

```sh
composer install --no-interaction --no-scripts --no-progress --prefer-dist
php bin/audit-installed-dependencies.php
composer check-platform-reqs
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter 'TemplateHelperIntegrationTest|WiseCommandHostTest|WiseResourceClassResolverTest|ComposerMetadataTest'
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --log-junit runtime-junit.xml
```

Compare the lock hash before and after. Retain the JSON audit and JUnit report
with the source revision and runtime versions. Package references in the
snapshot describe the development graph, including Wise; they are not a
production `--no-dev` support claim. Do not restore over edited dependencies.
A failed proof should leave the active deployment unchanged; discard only the
isolated destination after preserving its evidence. Deployment rollback and
data recovery still require separate rehearsal.

## Unresolved warnings and skipped coverage

- The locked Kapsle revision declares `LogTypeEnum` in both `LogTypeEnum.php`
  and `TenantStatusEnum.php`. Composer reports ambiguous class resolution.
  [Kapsle issue #2](https://github.com/BlueFissionTech/opus-addon-kapsle/issues/2)
  owns the correction. Passing Opus tests does not establish tenant enum or
  database lifecycle correctness.
- BlueCore's shutdown handler labels every retained `error_get_last()` value
  fatal. It prints a suppressed Composer missing-file probe as a fatal error
  after generating that file successfully. It also prints a handled hard-link
  warning after PHPUnit has completed with exit 0. This is unresolved upstream
  reporting behavior; preserve and inspect the messages rather than suppressing
  the handler or treating the runtime as warning-free.
- One skipped test requires the optional BotMan transport. Seven runtime-path
  tests and one generation test require symlink support; one generation test
  requires POSIX permissions; one requires a working hard-link fixture. The
  snapshot names all eleven tests. Their security assertions remain unproven
  on this runtime and require a suitable platform run.

## Remaining release gates

A separate run must prove anonymous public dependency acquisition, production
installation without development packages, installation as a dependency in a
host application, Linux link/permission behavior, optional transports, and real
web/database installation, configuration, upgrade and recovery. External
providers, deployment, and the full direct-dependency conformance matrix are
also outside this snapshot. No application or data migration was deployed by
this proof. Issues #104 and #107 remain open.
