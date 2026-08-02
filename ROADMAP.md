# Opus Framework Roadmap

## Current Slice

### Runtime Contract Proof

Status: in progress on issue 8.

Goals:

- expose runtime contract readiness through `contract proof`
- list optional target scripts through `contract targets`
- provide local validation instructions through `contract validate`
- keep interpreter-backed validation optional for the default test suite
- route parser and runtime gaps to their owning packages

Review boundary:

- this slice should prove the contract surface and tests
- broader CLI, frontend, and lifecycle cleanup should be tracked as separate
  issues

## Next Slices

### Wise-Centered Command Kernel

Framework should organize backend command invocation around Wise as the central
kernel. The work should isolate command metadata, registration, validation,
execution results, and error reporting so human and agent callers share one
contract.

### Test Coverage And Gates

Test coverage should become consistent across service, console, command, and
frontend build surfaces. PHPUnit configuration should match the installed
runner, optional integration tests should remain opt-in, and build validation
should be easy to run locally and in CI.

### Addon Lifecycle Readiness

Addon and theme lifecycle work should move from proof scripts into acceptance
tests and runtime readiness checks. The lifecycle contract should stay generic
and reusable across application types.

### Backend CLI Hygiene

Existing console managers and terminal entry points need a tidy command
contract: clear groups, typed inputs where practical, structured results,
consistent output, and predictable failure handling.

### Frontend Build Hygiene

Webpack and package metadata need cleanup before production release:

- replace stale template branding with Opus metadata
- make theme selection configurable through the platform contract
- harden addon entry discovery
- remove or isolate legacy asset folders
- verify copied asset paths before build
- add build and lint validation to the documented test surface

### Documentation And Release Readiness

README, specification, roadmap, and tests documentation should stay aligned with
the issue backlog. Each production-facing feature should have acceptance
criteria, validation commands, and clear ownership boundaries.

## Sequencing

1. Finish runtime contract proof validation.
2. Align command invocation around Wise kernel contracts.
3. Harden PHPUnit and command coverage gates.
4. Add addon lifecycle readiness acceptance tests.
5. Clean backend CLI surfaces.
6. Modernize webpack and asset build contracts.
7. Reconcile docs and release-readiness checks before publication.
