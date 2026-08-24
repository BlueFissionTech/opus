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

Use a conventional Composer package with the PSR-4 namespace
`AddOns\\<PackageName>\\` mapped to `logic/`. An installed package currently
occupies `addons/<directory>/`, where `<directory>` is the manager's discovery
key. Generate and validate the canonical structure with:

```shell
php bin/opus-addon.php generate sample_tools addons/sample_tools
php bin/opus-addon.php validate addons/sample_tools
```

The output parent must already exist. Generation validates a temporary tree,
holds a target-specific publication lock, and claims the destination with an
atomic no-clobber directory creation. It moves validated contents first and
publishes `definition.json` last as the BlueCore discovery marker. Runtime
discovery therefore cannot load a partially populated add-on, and cooperating
or independent writers cannot have their destination replaced. When the
destination is an immediate child of `addons/`, its directory name must exactly
match the requested lifecycle key.
The lifecycle key and installer directory use lowercase snake case
(`sample_tools`) because the locked lifecycle manager also uses the key as the
install/uninstall hook stem. Composer package slugs are normalized separately
to kebab case (`opus-addon-sample-tools`). Their current values correspond, but
they are distinct package and runtime identities.
Generated Composer metadata defaults to the valid `proprietary` license
identifier. Replace it with the intended SPDX identifier only when the package
has an approved open-source release license.

```text
composer.json
definition.json
main.php
README.md
logic/
    Business/
        Http/
        Managers/
        Prompts/
    Domain/
        Models/
        Queries/
        Repositories/
        Values/
    Registration/
mapping/
    agents.php
    api.php
    app.php
    console.php
    default.php
    menus.php
resource/
    markup/
        default.vibe
    src/
datasources/
    generator/     # required; may be empty
    structure/     # required; may be empty
tests/
phpunit.xml
```

Mapping files use one of two contracts. The generated form contains an optional
strict-types declaration followed by a single returned array. Existing packages
may instead import `BlueFission\Services\Mapping` and contain only
`Mapping::add()` or `Mapping::crud()` registrations with fluent mapping policy
calls. Arbitrary top-level assignments, includes, and function calls remain
invalid. In particular, `menus.php` returns an empty result when no navigation
service is supplied so isolated contract validation does not invent a global
navigation dependency. Invalid menu declarations remain a validation failure;
optional capability handling is not an error-suppression boundary.

### Agent command boundaries

`mapping/agents.php` is a versioned, data-only capability map. It does not
construct agents, connect to inference providers, register commands, or perform
IO. The root application owns `opus.central`; each add-on owns its exact
`addon.<name>` boundary. A generated add-on defaults to `generated` mode with an
empty tool list, so installation never grants ambient command access.

Agent maps use exact `resource.action` tool identifiers declared by the
package's `mapping/console.php` resource catalog. The supported modes are:

- `specialist`: use the package's declared specialist profile and local tools;
- `generated`: create a reviewable default specialist from package metadata;
- `central`: decline a specialist and request narrowly reciprocal imports into
  the root agent; and
- `disabled`: expose no package commands to agents.

Existing add-ons may omit both the manifest entry and agent map while they are
migrated. They remain valid but expose no agent commands. Declaring an agent map
makes the file and its closed schema mandatory. For supported executable
console mappings, stable dotted `Mapping::add()` names (or two-segment paths)
and deterministic `Mapping::crud()` resources are extracted statically; the
mapping file is never executed during validation.

Local tools, imports, exports, permissions, and active lifecycle states must be
explicit. Missing maps, inactive packages, missing permissions, malformed
identifiers, and one-sided cross-agent grants resolve to no tools. Grants are
non-transitive. Specialist tools are never copied into the central map; the
central agent reaches those capabilities through explicit delegation. Add-on
agents receive no root or peer tools unless both maps name the exact reciprocal
grant.

The application binds Wise's `ICommandProcessor` to the scoped processor, which
parses a command before execution, derives its exact tool identifier, and checks
the same resolved map used for discovery. The already-authorized `Command`
object is passed to Wise for execution so free-form input is not parsed twice. Denied
commands and continuations return structured diagnostics before handler
execution. Continuations are bound to their originating agent, tenant, and
actor, then reauthorized against current lifecycle and permission state.
Request context should include actor, agent, tenant, correlation,
active add-ons, lifecycle states, and granted capabilities. Returned command
results include the resolved map versions and decision metadata and must be
consumed once rather than treated as another command.

Agent runtime composition is separate from command-map declaration. Opus
registers central and add-on descriptors without constructing an inference
client. An injected runtime factory resolves the opaque profile reference and
owns provider selection, including hosted and self-hosted providers, while the
Opus composition service owns lifecycle policy and tenant isolation. Persisted
runtime state is keyed by tenant and agent; process-local runtime adapters may
be reconstructed by the factory for a later request. Factories must therefore
rehydrate their own provider/session context from the bounded descriptor and
runtime context instead of relying on ambient globals.

Specialist agents require an active package lifecycle state and explicit
tenant. They do not inherit central or peer command surfaces. The central
agent delegates a bounded task to a specialist runtime; it does not absorb the
specialist's tool map. Task output, diagnostics, traces, cancellation, and
failure recovery remain structured and provider-neutral so Automata can own
hierarchical or peer orchestration without moving provider semantics into the
application.

`definition.json` declares registration behavior through a package-owned class
and a primary-file factory. The validator derives the registration file from
the declared class instead of requiring a fixed filename. Theme entries declare
a directory beneath `resource/markup/` and a `.vibe` entrypoint; this supports
both a single-file default and directory-backed themes. Generated test bootstrap
code first resolves a package-local Composer autoloader and then an application-
root autoloader for packages installed under `addons/`.

Server-rendered templates use `.vibe`, canonical `{$value}` variables,
executable `.vibe` includes, and named regions. Client-side Reactor bindings
remain separate from the server template contract.

Existing add-ons that map `AddOns\\<PackageName>\\` to the package root should
migrate the mapping to `logic/` while correcting file namespaces. Run
`composer dump-autoload -o` and the Opus validator before publishing that
change. Keep compatibility shims explicit and temporary; the scaffold does not
generate aliases for historical namespaces.

Canonical namespace casing is `AddOns\\`. Packages using historical `Addons\\`
casing must update Composer PSR-4 metadata, declarations, and references in the
same compatibility release; do not maintain permanent case-only aliases.
Domain value objects belong in `logic/Domain/Values`. Packages using
`Domain/Value` or `Domain/ValueObjects` should move the files and update their
namespaces together, with a temporary explicit forwarding class only when a
published API requires a migration window.

### Runtime discovery contract

The current manager scans each immediate directory under `addons/`. A discoverable add-on must therefore provide:

- `addons/<directory>/definition.json`, containing valid JSON metadata;
- an explicit, nonempty `name` that exactly matches `<directory>`, because current lifecycle APIs use the same value as both metadata identity and directory lookup key;
- a `name` matching `[A-Za-z_][A-Za-z0-9_]*` when lifecycle hooks are provided, because the manager also uses it verbatim as the PHP function-name stem;
- a nonempty `version` identifying the add-on release;
- a nonempty `namespace` identifying the add-on's PHP namespace;
- an optional `description`;
- an optional `libraries` array of Composer package names installed into or removed from the application root; and
- a nonempty `primary_file`, normally `main.php`.

The primary file is required for runtime loading and lifecycle hooks. Active add-ons are loaded from the stored add-on path plus `primary_file`. Installation and uninstallation load that same file and call `<name>_install()` or `<name>_uninstall()` when the corresponding function exists. Declare both hook functions in the global namespace; the current manager performs an unqualified lookup and does not resolve namespaced functions.

Installation configures datasource migrations from `datasources/structure/` and datasource generators from `datasources/generator/` unconditionally, so both directories must exist. Each structure file must expose a global-namespace class with `change()` and `revert()` methods. Each generator file must expose a global-namespace class with `populate()`. A global-namespace `RootSeeder.php` class may instead provide `seeders()` to select and order the remaining generator classes. The locked loader resolves extracted short class names without their declared namespace. Keep those resources inside the add-on directory and make their work safe to repeat. Activation and deactivation persist add-on state; activation does not replace installation or dependency setup.

The `libraries` list executes root-level Composer require commands during installation and remove commands during uninstallation. Because root requirements are shared by every add-on, removal must be coordinated so one package does not remove a dependency still owned by another.

Composer metadata remains the package and dependency boundary, but it is not currently the runtime discovery mechanism. Install the package as an application-root Composer dependency with `type: opus-addon`. The locked BlueCore Composer plugin then places it under `addons/<package-name>/` while Composer registers the package's autoload metadata. Copying a package into `addons/` without Composer installation does not register its PSR-4 namespace.

`composer.json` should:

- declare a stable, package-owned name and description;
- declare `type: opus-addon` so the BlueCore Composer plugin selects the add-on install path;
- define PSR-4 autoloading for the package namespace;
- require PHP 8.2 or later;
- require compatible, published versions of Opus and the Blue Fission packages used directly by the add-on; and
- declare package metadata and direct dependencies independently of the runtime `definition.json` metadata.

Do not scan arbitrary directories or modify the platform's Composer configuration from inside the add-on. Treat the documented `addons/<directory>/definition.json` layout as a compatibility contract until the platform exposes a package-owned discovery registry.

## Coding and public standards

Use strict types in new PHP files unless an established package boundary requires otherwise. Prefer DevElation primitives, behaviors, services, data objects, connections, and helpers where the Blue Fission ecosystem already owns the capability. Keep raw native operations at implementation or external-contract boundaries where wrapping would hide important semantics.

Public APIs, documentation, issues, and pull requests must remain package-owned and collaborator-facing. Do not expose workstation paths, private coordination details, or scratch artifacts. Describe reusable acceptance criteria instead of justifying a capability around one consumer-specific request.

## Lifecycle design

Package-owned lifecycle resource operations must be repeatable, observable, and safe to retry after a partial failure. The locked manager does not guarantee that its registration or orchestration steps are retryable.

### Install

Installation prepares package-owned resources and records only the state needed to make those resources usable. The current manager runs datasource migrations, populates datasource state, invokes the optional `<name>_install()` hook, and records the add-on. Schema setup, seed data, configuration defaults, and hook behavior must be idempotent. Installation must not silently activate unrelated capabilities.

### Activate

Activation marks an installed add-on active so the manager can load its configured primary file during runtime bootstrap. Runtime registration performed by that file should validate required configuration and fail with a clear, actionable error when prerequisites are unavailable. An activated package must not take ownership of global behavior outside its declared capability.

### Deactivate

Deactivation marks the add-on inactive so it is omitted from later active-add-on loading. Add-on runtime behavior should make shutdown and repeated bootstrap safe, and package data should be preserved by default so reactivation is safe. Any exception to that retention policy must be explicit in the package documentation and require deliberate operator action.

### Uninstall

Uninstallation invokes the optional `<name>_uninstall()` hook, removes package registration, and reverts the add-on's datasource migration batch. The locked manager deletes registration before dependency removal and migration reversion, so a failed manager-level uninstall is not safely retryable by the same add-on ID and requires operator reconciliation. Destructive data removal must be explicit, documented, and independently confirmable; it must never be an implicit side effect of deactivation.

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
- Verify package-owned migrations, generators, and hooks are safe to repeat. The current manager may create duplicate registration records, so manager-level repeated installation is not an idempotency guarantee.
- Verify activation exposes only the intended runtime behavior.
- Verify deactivation removes runtime behavior without deleting retained data.
- Verify uninstall follows the documented data policy.
- Verify the package can be discovered and managed through the supported Opus add-on lifecycle.
- Run `composer validate --strict` and `vendor/bin/phpunit --do-not-cache-result` before release.
- Declare the tested Opus and direct dependency versions in the release notes.

## Review questions

Before accepting an add-on change, confirm that the capability belongs in a reusable package, the package has no direct platform-core edits, package-owned lifecycle resource actions are idempotent, configuration and persistence boundaries are explicit, and compatibility is documented. When an integration need is shared, extend the relevant upstream package through its public contract instead of embedding a one-off adapter in the add-on.
