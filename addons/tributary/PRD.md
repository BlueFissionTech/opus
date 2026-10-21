# Tributary: governed project delegation

## Purpose and first release

Tributary reduces the work of assigning repeatable tasks to a coordinated team
while preserving human control of membership, permissions and execution. It is
an optional source package within Opus, not an independently deployed service.

An operator supplies a versioned project catalog containing agent profiles,
roles, memberships and fixed task assignments. An authenticated member can
discover that project's profiles and tasks, then execute an assigned task through
the same Wise command processor used by UI, CLI and governors. The host resolves
fresh identity and capabilities for every execution. Catalogs and prompts never
authenticate a caller or confer host privileges.

## Acceptance criteria

- Package discovery and command parsing perform no runtime work.
- Application, tenant, project and actor membership must match before discovery
  or execution; denied and nonexistent projects have the same public response.
- Execution requires the intersection of host grants and project role grants.
  A task receives only its explicitly required capabilities, never delegation
  or runtime-administration privileges inherited from its caller.
- Source and targets come from a validated assignment. Specialists retain their
  own capability maps, lifecycle and permission checks in Opus composition.
- Text and structured commands share Wise parsing and the same service.
- Synematic owns the execution workflow; Automata owns hierarchical orchestration.
  The host supplies the delegation service and can configure a fresh native
  workflow for each assignment. Workflow observations contain phase markers,
  not authenticated context, task content or private agent results.
- Deterministic execution uses the actual Automata hierarchical orchestrator.
  Results remain data, including failures and partial results; no automatic
  retry, result execution or speculative provider fallback is permitted.
- Tests exercise one and multiple specialists, denied scope/role/capability,
  runtime unavailability, stopped runtimes, invalid commands and reentry.

## Boundaries and release gates

Opus owns authentication, runtime enrollment and persistence; Automata owns
orchestration. Profile references are opaque Synthetiq profile identifiers, not
new conversation semantics. Wise owns command parsing. Project configuration is
immutable for a service instance; host reconstruction applies reviewed changes.

This first slice is synchronous and has no durable task queue, task cancellation,
exactly-once/retry ledger, project editor, transient-agent provisioning or remote
service. Runtime cancellation remains on Opus's authorized runtime surface.
Concurrent workers, persistent assignments and tenant deployment qualification
must be proven before service promotion. Installation does not activate an agent.

## Evidence and outcomes

Use the package contract validator and the package tests in the host test suite.
Track completed/failed/partial assignments and denied actions, not model activity
or token volume as success. Operators must see uncertainty and retain the ability
to stop a runtime. A completion is the orchestrator's report, not independent
verification of external effects or achievement of a human goal.
