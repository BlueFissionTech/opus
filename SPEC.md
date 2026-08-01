# Opus Next Integration Specification

## Purpose

Opus is the application platform layer for Blue Fission products. The next
version should run its command and automation center through Wise, delegate
agent orchestration to Automata, use Vibrato for authored templates and
generation flows, and keep add-ons isolated behind their own package-owned
activation and service contracts.

## Current Integration State

- DevElation, BlueCore, Automata, Wise, Vibrato, Presence, Synematic,
  SimpleClients, and the currently installed add-on packages are declared
  through Composer VCS repositories.
- The Composer lock resolves these packages on PHP 8.2 with Opus-owned
  websocket dependencies kept explicit for the terminal surface.
- Reactor is declared as the Blue Fission frontend package for the JavaScript
  presentation layer, with the existing local dashboard modules still present
  until they are migrated feature by feature.
- App registration already injects the Wise command processor and BlueCore
  add-on manager. The Wise kernel remains the correct owner for shell routing,
  bridge execution, virtual root behavior, and interpreter dispatch.
- Opus now exposes a Vibrato-backed generation service for syntax validation,
  deterministic rendering, and bounded file output inside the application
  workspace.

## Target Architecture

### Command Kernel

Wise should be treated as the central command kernel. Opus should register the
resources, services, add-on commands, and user-facing surfaces that Wise can
route to, but Opus should not duplicate Wise parsing, bridge dispatch, memory,
or virtual filesystem responsibilities.

Acceptance criteria:

- Opus command surfaces resolve through Wise-owned command contracts.
- Console managers expose small, testable operations that Wise can call.
- Script execution routes through Wise bridges rather than bespoke Opus
  interpreters.

### Agent Orchestration

Automata should own orchestration primitives for application and add-on agents.
Opus should provide application context, registered services, and observable
results.

Acceptance criteria:

- Add-on agents can be described and activated independently.
- Central orchestration can inspect add-on readiness without taking ownership
  of add-on internals.
- Agent outputs are represented as stable command or service results.

### Templates And Generation

Vibrato should own Vibe parsing, validation, rendering, and generation
contracts. Opus should provide templates, variables, and safe output paths.

Acceptance criteria:

- Vibe sources can be validated before execution.
- Deterministic Vibe templates can render without backend side effects.
- Rendered artifacts can be written only inside the application workspace.
- Code generation entrypoints return structured success/error data that tests
  and command resources can inspect.

### Add-On Boundary

Add-ons should be package-owned and individually testable. Opus installs,
activates, deactivates, and surfaces them through BlueCore add-on contracts.

Acceptance criteria:

- Installed add-ons do not require Opus to patch their namespace or autoload
  behavior.
- Add-on readiness checks report package issues as package issues.
- Opus exposes lifecycle commands without coupling to add-on implementation
  internals.

### Frontend Presentation

Reactor should replace the legacy dashboard presentation code incrementally.
The migration should keep existing screens stable while replacing shared
binding, module, response, and transport concerns with package-owned Reactor
exports.

Acceptance criteria:

- Shared frontend bindings come from Reactor where an equivalent export exists.
- Legacy dashboard modules are migrated in focused slices with build coverage.
- Webpack remains a bundler detail, not the source of UI contracts.

## Known Gaps

- Installed add-on packages currently emit optimized-autoload warnings, and one
  transitive authentication dependency has a reported advisory. Compatibility
  fixes are tracked in the packages that own those constraints.
- Composer validation still reports the existing `Exclusive` license metadata
  as a non-SPDX value. The package license should be confirmed before changing
  public metadata.
- The PHPUnit configuration still uses schema entries removed by the installed
  PHPUnit version.
- The frontend still imports legacy dashboard modules directly; the Reactor
  dependency is present but not yet wired through the application entrypoints.
- The terminal surface still uses the existing Ratchet integration. A later
  slice should decide whether that remains the websocket transport or becomes
  a Wise-mediated console channel.

## Implemented In This Slice

- Refreshed Composer dependencies for the current Blue Fission package graph.
- Added the current add-on packages as Composer-managed Opus add-ons.
- Added Vibrato, Wise, Presence, Synematic, and supporting Blue Fission
  repositories to the Composer graph.
- Added Reactor to the npm graph.
- Added `VibeGenerationService` as the Opus-owned generation facade.
- Updated `CodeManager` to use the Vibrato service instead of the incomplete
  scaffold factory placeholder.
- Added focused PHPUnit coverage for Vibrato validation, rendering, and bounded
  file output.
