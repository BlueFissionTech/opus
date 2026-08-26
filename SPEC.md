# Opus Framework Specification

## Purpose

Opus is the application platform layer for Blue Fission products. The next
version should run its command and automation center through Wise, delegate
agent orchestration to Automata, use Vibrato for authored templates and
generation flows, and keep add-ons isolated behind their own package-owned
activation and service contracts.

Wise is the central command kernel for human and agent invocation. Framework
console commands should remain capability-first and expose stable contracts
that Wise can discover, route, validate, and report without binding the
platform to a single workflow or consumer.

## Scope

Framework owns:

- runtime host contracts and readiness reporting
- add-on and theme lifecycle orchestration
- backend command registration and invocation surfaces
- application-level integration of first-party Blue Fission libraries
- frontend asset bundling and Reactor-oriented presentation entry points
- production validation contracts for tests, build, and release readiness

Framework does not own:

- interpreter grammar or parser behavior
- reusable intelligence algorithms below the application platform layer
- external service credentials or deployment secrets
- consumer-specific application features

## Current Integration State

- DevElation, Automata, BlueCore, Chronicler, SimpleClients, and Synthetiq
  resolve through tagged Packagist releases. Wise, Vibrato, Presence,
  Synematic, and the currently installed add-on packages use explicit root VCS
  repositories.
- The Composer lock resolves these packages on PHP 8.2 while the terminal
  WebSocket transport remains optional for hosts with compatible dependencies.
- Reactor is declared as the Blue Fission frontend package for the JavaScript
  presentation layer, with the existing local dashboard modules still present
  until they are migrated feature by feature.
- App registration already injects the Wise command processor and BlueCore
  add-on manager. The Wise kernel remains the correct owner for shell routing,
  bridge execution, virtual root behavior, and interpreter dispatch.
- Opus now exposes a Vibrato-backed generation service for syntax validation,
  deterministic rendering, and bounded file output inside the application
  workspace.

## Runtime Contract Proof

The runtime contract proof describes the platform surface through a manifest,
fixture payload, executable scripts, and optional target scripts. It is exposed
through capability-first commands:

- `contract proof`
- `contract targets`
- `contract validate`

The proof remains a framework-level validation asset. Its source format is an
implementation detail; the durable contract is the host, lifecycle, readiness,
resource, and feedback behavior being exercised.

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
- Agent descriptors register without constructing provider clients or runtime
  sessions.
- Runtime factories remain provider-neutral and may resolve hosted,
  self-hosted, or fallback providers from the descriptor's opaque profile
  reference.
- Central and specialist runtime instances are isolated by agent and tenant;
  specialist startup requires an explicit tenant, active add-on state, and
  current capability grant.
- Start, suspend, resume, stop, and cancel transitions are idempotent and
  persist structured state independently from process-local runtime objects.
- Each runtime operation receives the current tenant, actor, capability, and
  correlation context even when a provider adapter is reused across requests.
- Successful cancellation is persisted and deduplicated until a subsequent
  task execution begins for that agent scope.
- Every provider execution receives an immutable execution identifier, and
  cancellation targets that exact generation so an older cancellation cannot
  affect a replacement execution on another host.
- Each tenant-and-agent scope admits one active provider execution at a time;
  overlapping requests fail closed until that generation completes.
- Shared runtime state uses an injected atomic synchronization boundary. MySQL
  hosts use connection-scoped advisory locks; lifecycle and cancellation claims
  carry bounded leases so dead workers can be recovered without host-local lock
  assumptions.
- Task execution revalidates lifecycle and permission policy and returns
  provider-neutral output, diagnostics, trace, and correlation metadata.
- Opus selects permitted participants and context. Automata owns hierarchical
  and peer orchestration execution; Opus does not reimplement orchestration
  patterns.
- Delegation admits only the application coordinator and explicitly selected
  specialist or generated add-on agents. The coordinator receives participant
  identifiers instead of specialist tool maps, while each specialist executes
  through its own capability, tenant, lifecycle, and runtime boundary.
- Automata hierarchical orchestration receives normalized, provider-neutral
  worker outcomes. Specialist workers do not inherit peer results unless a
  later collaboration contract explicitly grants that exchange.

### Templates And Generation

Vibrato should own Vibe parsing, validation, rendering, and generation
contracts. Opus should provide templates, variables, and safe output paths.

Acceptance criteria:

- Vibe sources can be validated before execution.
- Deterministic Vibe templates can render without backend side effects.
- Shipped themes use `.vibe` sources, canonical `{$value}` output, named
  application regions, and executable file includes.
- Theme context is escaped recursively unless a renderer call identifies a
  field as trusted markup at an explicit composition boundary.
- The application registers its Vibe renderer as the canonical `template`
  service and retains `vibe.theme` as a compatibility alias.
- Ordinary theme rendering uses BlueCore's global `template(theme, file, data)`
  facade. Rendering that carries application-owned trust policy may call the
  canonical service directly rather than extending the helper signature.
- Browser-side Reactor bindings remain distinct from server-side Vibe
  variables so initial rendering does not consume live client placeholders.
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

## Testing Contract

Baseline tests must cover:

- runtime contract manifest and readiness behavior
- console command summaries and target listing
- optional interpreter validation without requiring the interpreter in the
  default unit suite
- command registration, failure, and output contracts for the Wise-aligned CLI
- generation validation, rendering, path boundaries, and publication behavior
- frontend build contracts once webpack cleanup begins

Optional integration tests remain opt-in and must not require secrets on a
clean checkout.

## Known Gaps

- Installed add-on packages currently emit optimized-autoload warnings, and one
  transitive authentication dependency has a reported advisory. Compatibility
  fixes are tracked in the packages that own those constraints.
- Composer validation still reports the existing `Exclusive` license metadata
  as a non-SPDX value. The package license should be confirmed before changing
  public metadata.
- The frontend still imports legacy dashboard modules directly; the Reactor
  dependency is present but not yet wired through the application entrypoints.
- The terminal surface can use the optional Ratchet integration when the host
  dependency graph supports it. A later slice should decide whether that
  remains the websocket transport or becomes a Wise-mediated console channel.

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
- Added runtime contract manifests, validation commands, fixtures, and focused
  readiness coverage.
- Updated the PHPUnit configuration to the supported PHPUnit 9.6 schema.

## Release Acceptance

- Roadmap items have issue-sized slices with clear acceptance criteria.
- Runtime contract proof tests pass in the default unit suite.
- Backend CLI work remains organized around Wise kernel compatibility.
- Generation operations return structured results and preserve workspace
  boundaries.
- Webpack and asset-pipeline cleanup remain separately tracked.
- Specifications and tests stay synchronized as each slice lands.
