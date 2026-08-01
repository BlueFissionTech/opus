# Opus Add-On Authoring Guide

## Purpose and scope

An Opus add-on is a separately versioned package that contributes a coherent capability to an Opus installation. It owns its domain behavior, configuration, resources, and lifecycle work. Opus owns discovery, installation state, activation state, and the management surface that invokes those lifecycle operations.

Build an add-on around a durable capability. Its public API and configuration should be useful wherever that capability is needed, rather than being shaped around one application, tenant, or workflow.

## Package boundary

An add-on must keep its implementation inside its own package. Do not patch Opus application files or write directly to platform add-on state. The current BlueCore manager discovers installed add-ons through the application-owned `addons/` directory, so package installation must preserve the runtime layout described below.

Use the platform add-on manager for lifecycle operations. In the current application, the manager owns install, activate, deactivate, uninstall, and active-package loading; add-on state is persisted through the BlueCore add-on query and repository contracts.

Keep these responsibilities separate:

| Area | Add-on responsibility | Platform responsibility |
| --- | --- | --- |
| Domain behavior | Services, models, policies, and package-owned APIs | Application composition and shared runtime facilities |
| Metadata | Name, description, version compatibility, and discovery metadata | Discovery and installed/active state tracking |
| Lifecycle | Idempotent resource setup and teardown behavior | Invoking lifecycle operations and loading active add-ons |
| Configuration | Defaults, validation, and documented environment inputs | Supplying installation-level configuration facilities |
| Resources | Routes, commands, templates, assets, migrations, and translations when applicable | Registering the add-on through the platform lifecycle |

## Package layout

Use a conventional Composer package with a clear PSR-4 namespace. An installed package currently occupies `addons/<directory>/`, where `<directory>` is the manager's discovery key. The following layout includes the files consumed directly by the BlueCore manager; omit optional directories that do not serve the add-on's scope.

```text
composer.json
definition.json
main.php
README.md
src/
    Application/
    Domain/
    Infrastructure/
    Presentation/
config/
resource/
    migrations/
    templates/
    translations/
datasources/
    generator/
    structure/
tests/
```

### Runtime discovery contract

The current manager scans each immediate directory under `addons/`. A discoverable add-on must therefore provide:

- `addons/<directory>/definition.json`, containing valid JSON metadata;
- an explicit, nonempty `name` that exactly matches `<directory>`, because current lifecycle APIs use the same value as both metadata identity and directory lookup key;
- a `name` matching `[A-Za-z_][A-Za-z0-9_]*` when lifecycle hooks are provided, because the manager also uses it verbatim as the PHP function-name stem;
- a nonempty `version` identifying the add-on release;
- a nonempty `namespace` identifying the add-on's PHP namespace;
- an optional `description`;
- an optional `libraries` array of Composer package names used to report explicit dependency commands; and
- an optional `primary_file`, which defaults to `main.php`.

The primary file is required for runtime loading and lifecycle hooks. Active add-ons are loaded from the stored add-on path plus `primary_file`. Installation and uninstallation load that same file and call `<name>_install()` or `<name>_uninstall()` when the corresponding function exists. Declare both hook functions in the global namespace; the current manager performs an unqualified lookup and does not resolve namespaced functions.

Installation configures datasource migrations from `datasources/structure/` and datasource generators from `datasources/generator/`; the current manager skips either area when its directory is absent. When present, each structure file must expose a discoverable class with `change()` and `revert()` methods. Each generator file must expose a discoverable class with `populate(bool $auto)`. A `RootSeeder.php` class may instead provide `seeders()` to select and order the remaining generator classes. Keep those resources inside the add-on directory and make their work safe to repeat. Activation and deactivation persist add-on state; activation does not replace installation or dependency setup.

The `libraries` list produces root-level Composer require or remove commands. The manager reports those commands by default and executes them only when dependency mutation is explicitly enabled for install or uninstall. Because root requirements are shared by every add-on, removal must be coordinated so one package does not remove a dependency still owned by another.

Composer metadata remains the package and dependency boundary, but it is not currently the runtime discovery mechanism. A package installer or deployment process must place the package in the required `addons/<directory>/` layout without modifying application source files.

`composer.json` should:

- declare a stable, package-owned name and description;
- define PSR-4 autoloading for the package namespace;
- require PHP 8.2 or later;
- require compatible, published versions of Opus and the Blue Fission packages used directly by the add-on; and
- declare package metadata and direct dependencies independently of the runtime `definition.json` metadata.

Do not scan arbitrary directories or modify the platform's Composer configuration from inside the add-on. Treat the documented `addons/<directory>/definition.json` layout as a compatibility contract until the platform exposes a package-owned discovery registry.

## Coding and public standards

Use strict types in new PHP files unless an established package boundary requires otherwise. Prefer DevElation primitives, behaviors, services, data objects, connections, and helpers where the Blue Fission ecosystem already owns the capability. Keep raw native operations at implementation or external-contract boundaries where wrapping would hide important semantics.

Public APIs, documentation, issues, and pull requests must remain package-owned and collaborator-facing. Do not expose workstation paths, private coordination details, or scratch artifacts. Describe reusable acceptance criteria instead of justifying a capability around one consumer-specific request.

## Lifecycle design

Lifecycle operations must be repeatable, observable, and safe to retry after a partial failure.

### Install

Installation prepares package-owned resources and records only the state needed to make those resources usable. The current manager runs datasource migrations, populates datasource state, invokes the optional `<name>_install()` hook, and records the add-on. Schema setup, seed data, configuration defaults, and hook behavior must be idempotent. Installation must not silently activate unrelated capabilities.

### Activate

Activation marks an installed add-on active so the manager can load its configured primary file during runtime bootstrap. Runtime registration performed by that file should validate required configuration and fail with a clear, actionable error when prerequisites are unavailable. An activated package must not take ownership of global behavior outside its declared capability.

### Deactivate

Deactivation marks the add-on inactive so it is omitted from later active-add-on loading. Add-on runtime behavior should make shutdown and repeated bootstrap safe, and package data should be preserved by default so reactivation is safe. Any exception to that retention policy must be explicit in the package documentation and require deliberate operator action.

### Uninstall

Uninstallation invokes the optional `<name>_uninstall()` hook, removes package registration, and reverts the add-on's datasource migration batch. Destructive data removal must be explicit, documented, and independently confirmable; it must never be an implicit side effect of deactivation.

## Integrations and configuration

Use Blue Fission services, data objects, connections, CLI helpers, and behavior primitives where they fit the package boundary. Keep external service adapters behind package-owned interfaces and make optional integrations opt-in.

Configuration should have documented defaults, validation, and a clear precedence order. Never commit credentials. Validate configuration at the boundary where it is consumed, and return errors that identify the missing or invalid setting without exposing secret values.

Prefer explicit contracts for shared interfaces:

- Define inputs, outputs, error conditions, and versioning expectations.
- Keep HTTP routes, commands, events, and configuration keys namespaced to the add-on.
- Add hooks only where a stable customization point is useful; document the value shape and test significant hook behavior.
- Keep migration ordering forward-moving, implement both `change()` and `revert()`, and remain compatible with supported platform releases.

## Testing and release checklist

An add-on is ready for review when it demonstrates the lifecycle and package boundary in automated tests.

- Unit-test package-owned domain behavior and configuration validation.
- Verify a clean install and a repeated install produce the same usable state.
- Verify activation exposes only the intended runtime behavior.
- Verify deactivation removes runtime behavior without deleting retained data.
- Verify uninstall follows the documented data policy.
- Verify the package can be discovered and managed through the supported Opus add-on lifecycle.
- Run `composer validate --strict` and `vendor/bin/phpunit --do-not-cache-result` before release.
- Declare the tested Opus and direct dependency versions in the release notes.

## Review questions

Before accepting an add-on change, confirm that the capability belongs in a reusable package, the package has no direct platform-core edits, lifecycle actions are idempotent, configuration and persistence boundaries are explicit, and compatibility is documented. When an integration need is shared, extend the relevant upstream package through its public contract instead of embedding a one-off adapter in the add-on.
