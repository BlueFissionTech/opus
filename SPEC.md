# Opus Framework Specification

## Purpose

Opus is the application platform layer for Blue Fission applications. It should
compose BlueCore application structure, DevElation primitives, Automata
intelligence surfaces, Annex interoperability, Reactor presentation bindings,
and Wise command invocation into one production-ready runtime.

Wise is the central command kernel for human and agent invocation. Framework
console commands should stay capability-first and expose stable contracts that
Wise can discover, route, validate, and report without binding the platform to a
single local workflow or one-off consumer.

## Scope

Framework owns:

- runtime host contracts and readiness reporting
- addon and theme lifecycle orchestration
- backend command registration and invocation surfaces
- application-level integration of first-party Blue Fission libraries
- frontend asset bundling and Reactor-oriented presentation entry points
- production validation contracts for tests, build, and release readiness

Framework does not own:

- interpreter grammar or parser behavior
- reusable intelligence algorithms below the application platform layer
- external service credentials or deployment secrets
- downstream application-specific features

## Runtime Contract Proof

The current runtime contract proof describes the platform surface through a
manifest, fixture payload, executable scripts, and optional target scripts. It
is intentionally exposed through capability-first commands:

- `contract proof`
- `contract targets`
- `contract validate`

The proof should remain a framework-level validation asset. The source format is
an implementation detail; the durable contract is the host, lifecycle,
readiness, resource, and feedback behavior being exercised.

## Wise Kernel Alignment

Backend commands should converge toward a command contract that Wise can use as
the central kernel:

- command groups and actions are explicit and capability-first
- command metadata can be inspected without executing side effects
- validation errors, execution failures, and successful results are structured
- interactive and non-interactive invocation use the same command semantics
- command output remains concise enough for shell users and agent callers

The legacy console mapping can remain as an adapter while the command contract
is made explicit and tested.

## Testing Contract

Baseline tests must cover:

- runtime contract manifest and readiness behavior
- console command summaries and target listing
- optional interpreter validation behavior without requiring the interpreter in
  the default unit suite
- command registration, failure, and output contracts as the Wise-aligned
  backend CLI surface is isolated
- frontend build contracts once webpack cleanup begins

Optional integration tests must remain opt-in and must not require secrets on a
clean checkout.

## Acceptance Criteria

- Roadmap items have issue-sized slices with clear acceptance criteria.
- Runtime contract proof tests pass in the default unit suite.
- Backend CLI work is organized around Wise kernel compatibility.
- Webpack and asset-pipeline cleanup is tracked separately from runtime proof
  work.
- Specifications and tests stay synchronized as each slice lands.
