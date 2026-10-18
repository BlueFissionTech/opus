# PHP compatibility audit

This bounded #150 increment preserves the PHP 8.2 floor. It does not certify
PHP 8.3, 8.4, or 8.5 runtime parity, upgrade dependencies, or expand CI spending.

## Current evidence and entrypoints

| Surface | Contract / evidence |
| --- | --- |
| Package and consumer template | `composer.json` and `templates/composer/opus-root.json` require `^8.2`; this permits later PHP 8.x versions but is not runtime proof |
| Web host | `public/index.php` resolves the host autoloader through `common/bootstrap/runtime.php`, then boots BlueCore and the session/CSRF path |
| Console host | `terminal` uses the same bootstrap and BlueCore host; `bin/opus-addon.php` is the Composer-exposed package command |
| Pre-install diagnostics | `bin/lint-php.php` compiles tracked PHP without Composer; `bin/audit-composer-vcs.php` validates dependency registration |
| Clean installation | `.github/workflows/baseline.yml` currently tests PHP 8.2 on Linux and Windows with locked, authenticated VCS installation and application scripts disabled |
| Newer interpreters | No PHP 8.3-8.5 execution evidence is supplied by this increment; existing source declarations and package constraints cannot establish parity |

The issue's original observation that no workflow matrix existed is superseded
by the committed PHP 8.2 OS matrix. It still is not a PHP-version matrix.
The lock includes private repositories and optional integrations; their access,
extension requirements, platform support and runtime behavior remain independent
constraints. A successful dependency solve cannot prove conversation or service
integration.

## Corrected declaration boundary

`ApplicationBuilderSkill`, `ModelBuilderSkill`, and `WebsiteBuilderSkill` declared
`execute(Context $context = null)`. PHP 8.4 deprecates this implicit nullability.
Each now declares `?Context`, preserving the existing optional argument, null
default, parameter name, public visibility and Automata `BaseSkill` inheritance.
No conversation body, return behavior, or dispatch route changes.

`BuilderSkillCompatibilityTest` characterizes those public contracts and compiles
each file in a fresh interpreter with all diagnostics enabled. Compilation is
deliberately separate from an already loaded class so compile-time deprecations
cannot be hidden by the class cache. On PHP 8.2 these are preservation checks;
the deprecation regression is exercised when the same test runs on PHP 8.4+.
This test does not invoke BotMan, provision a service, or certify the builder
conversations end to end.

Run `php vendor/phpunit/phpunit/phpunit --filter BuilderSkillCompatibilityTest`
with each approved, available interpreter. Record its actual version, warnings
and result; do not substitute parser emulation for runtime evidence.
See the [PHP 8.4 migration guide](https://www.php.net/manual/en/migration84.deprecated.php).

## Remaining compatibility and policy decisions

- PHP 8.3's method `#[\Override]` is a potential inheritance check. Its attribute
  syntax can parse on 8.2, but engine enforcement begins on 8.3 and reflective
  instantiation on 8.2 needs separate handling. It is not added by this fix.
  See [Override](https://www.php.net/manual/en/class.override.php).
- PHP 8.4 property hooks and PHP 8.5's pipe operator must not enter shared source
  while the 8.2 floor applies. Newer features require isolated version-specific
  code and consumer proof, or an explicit future floor decision. See the
  [8.5 release overview](https://www.php.net/releases/8.5/en.php).
- As reviewed on 2026-10-18, PHP 8.2 security support ends on 2026-12-31. Retaining
  it does not provide a twelve-month upstream security horizon. A maintainer
  must decide whether to grant a time-bounded support exception with ownership
  and an upgrade deadline, or change the future support policy. No exception is
  granted here. See [PHP supported versions](https://www.php.net/supported-versions.php).

Full entrypoint/extension coverage, dependency runtime audits, newer-interpreter
clean installs and the support-policy decision remain open under #150.
