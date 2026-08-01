# Opus Add-On Authoring Guide

## Purpose and scope

An Opus add-on is a separately versioned package that contributes a coherent capability to an Opus installation. It owns its domain behavior, configuration, resources, and lifecycle work. Opus owns discovery, installation state, activation state, and the management surface that invokes those lifecycle operations.

Build an add-on around a durable capability. Its public API and configuration should be useful wherever that capability is needed, rather than being shaped around one application, tenant, or workflow.

## Package boundary

An add-on must keep its implementation inside its own package. Do not patch Opus application files, write directly to platform add-on state, or depend on an installation path convention as part of the package contract.

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

Use a conventional Composer package with a clear PSR-4 namespace. The following layout is a neutral starting point; omit directories that do not serve the add-on's scope.

```text
composer.json
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
tests/
```

`composer.json` should:

- declare a stable, package-owned name and description;
- define PSR-4 autoloading for the package namespace;
- require compatible, published versions of Opus and the Blue Fission packages used directly by the add-on; and
- declare the metadata required by the supported add-on discovery contract.

Treat package metadata as the discovery boundary. Do not rely on a package's local directory name, manually scan arbitrary directories, or modify the platform's Composer configuration from inside the add-on.

## Lifecycle design

Lifecycle operations must be repeatable, observable, and safe to retry after a partial failure.

### Install

Installation prepares package-owned resources and records only the state needed to make those resources usable. Schema setup, seed data, and configuration defaults must be idempotent. Installation must not silently activate unrelated capabilities.

### Activate

Activation registers the add-on's runtime behavior through the supported extension points. It should validate required configuration and fail with a clear, actionable error when prerequisites are unavailable. An activated package must not take ownership of global behavior outside its declared capability.

### Deactivate

Deactivation removes runtime registrations and stops work initiated by the add-on. It should preserve package data by default so that reactivation is safe. Any exception to that retention policy must be explicit in the package documentation and require deliberate operator action.

### Uninstall

Uninstallation removes package registration and resources only after the add-on's data-retention policy has been applied. Destructive data removal must be explicit, documented, and independently confirmable; it must never be an implicit side effect of deactivation.

## Integrations and configuration

Use Blue Fission services, data objects, connections, CLI helpers, and behavior primitives where they fit the package boundary. Keep external service adapters behind package-owned interfaces and make optional integrations opt-in.

Configuration should have documented defaults, validation, and a clear precedence order. Never commit credentials. Validate configuration at the boundary where it is consumed, and return errors that identify the missing or invalid setting without exposing secret values.

Prefer explicit contracts for shared interfaces:

- Define inputs, outputs, error conditions, and versioning expectations.
- Keep HTTP routes, commands, events, and configuration keys namespaced to the add-on.
- Add hooks only where a stable customization point is useful; document the value shape and test significant hook behavior.
- Keep migrations forward-only and compatible with supported platform releases.

## Testing and release checklist

An add-on is ready for review when it demonstrates the lifecycle and package boundary in automated tests.

- Unit-test package-owned domain behavior and configuration validation.
- Verify a clean install and a repeated install produce the same usable state.
- Verify activation exposes only the intended runtime behavior.
- Verify deactivation removes runtime behavior without deleting retained data.
- Verify uninstall follows the documented data policy.
- Verify the package can be discovered and managed through the supported Opus add-on lifecycle.
- Run `composer validate --strict` and the full package test suite before release.
- Declare the tested Opus and direct dependency versions in the release notes.

## Review questions

Before accepting an add-on change, confirm that the capability belongs in a reusable package, the package has no direct platform-core edits, lifecycle actions are idempotent, configuration and persistence boundaries are explicit, and compatibility is documented. When an integration need is shared, extend the relevant upstream package through its public contract instead of embedding a one-off adapter in the add-on.
