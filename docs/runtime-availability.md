# Runtime availability and lifecycle

The `AgentCompositionService` availability boundary belongs to the application
host. Factories own the implementation of their local availability checks;
runtime/provider packages own transport health, retry algorithms and domain
recovery. These contracts implement bounded portions of #125; a complete operator
recovery plane remains outstanding.

## Inspection contract

`inspect($agentId, $context)` must apply the same tenant, capability, activated
add-on and lifecycle policy as runtime operations before probing a factory.
Denied inspection must not call the factory. An authorized inspection returns
the persisted lifecycle in `state`, local availability in `output.available`,
and the request correlation identifier in metadata. It must not construct or
invoke a runtime, write lifecycle records, start work, or retry a provider.

- An available local configuration returns `completed` with `available: true`.
- A factory that reports unavailable returns `unavailable`, `available: false`,
  and `agent_runtime_unavailable`.
- A probe exception returns `unavailable`, `available: false`, and
  `agent_runtime_probe_failed`; exception messages are not exposed.

`IAgentRuntimeFactory::available()` is a local, side-effect-free capability
probe. It must not instantiate runtimes, contact providers or perform retries.
Availability is not remote liveness, a readiness certificate, or an authority
grant. Every later operation still evaluates current policy; new work and
runtime construction also evaluate current availability.
An already-running lifecycle record can legitimately have unavailable runtime
capability. Idempotent lifecycle acknowledgments describe that saved state;
use inspection for current local availability.

## Failure isolation and explicit recovery

Execute and resume must not use a cached runtime after its factory reports
unavailable. An authorized suspend, stop or cancel request can still use an
existing cached runtime without probing or constructing a provider, so an outage
cannot remove the operator's cleanup controls. Current authorization and claim
checks still apply. Cleanup in a host without that cached runtime must probe
before construction. Unavailability preserves the existing lifecycle; it must
not manufacture a stop, cancellation or successful effect.
When local configuration becomes available again, an explicitly requested
operation can proceed through the existing claim and authorization checks.
Inspection and dispatch do not schedule automatic retries.
Public lifecycle results retain the host request correlation identifier on
denied, unavailable, failed, idempotent and successful paths, including explicit
recovery; runtime-supplied metadata cannot replace that identifier.

Unexpected exceptions caught by the composition service use
`agent_runtime_operation_failed`, keeping exception text out of its returned
and persisted diagnostics. This does not sanitize arbitrary provider-owned
outputs or declared runtime diagnostics; those contracts require their own
privacy review. Internal claim-contention reason matching remains unchanged.

Unexpected exceptions escaping delegation orchestration return
`agent_delegation_operation_failed` with the host tenant and correlation
identifiers. The failure result excludes exception messages, partial worker
results and conflict traces. Declared runtime outputs and diagnostics retain
their existing contract; this is not a general payload sanitizer.

A completion observer can fail after workers have performed effects. A failed
delegation result therefore does not establish that nothing ran or that effects
were rolled back. Delegation never automatically repeats that work; callers
must inspect the correlated activity before choosing an explicit retry.

## Execution state-store failures

`execute()` contains failures during authorization/state reads, claim acquisition,
runtime invocation and completion recording. A failed initial state read does not
construct or invoke a runtime. A failed claim write may already have committed;
the host does not retry that write or begin execution after the exception.

After runtime invocation, a failure while recording completion or reading its
result returns `agent_runtime_completion_unrecorded`. It does not trigger another
completion/cleanup write. An unresolved claim remains in place and continues to
block another execution. A storage error can also occur after a commit, so claim
presence or absence must be reconciled with execution evidence rather than guessed.

An exception from the runtime itself retains `agent_runtime_operation_failed`.
The existing compare-and-release cleanup is attempted once; a cleanup exception
cannot replace the bounded failure result or expose its message.

Exceptional execution results have `state: unknown`. This is an observation, not
a persisted lifecycle transition or permission to run work. They contain no task
payload or partial runtime output. Metadata supplies:

| Field | Meaning |
| --- | --- |
| `correlation_id` | Host request correlation |
| `execution_id` | Host-allocated identifier when one exists; allocation alone does not prove invocation |
| `failure_stage` | `preparing`, `executing`, or `recording` |
| `execution_started` | Whether the host began calling the runtime's execution method |
| `effects` | Always `unknown`; no rollback or absence-of-effects claim |
| `claim_release` | Exceptional runtime-cleanup attempt: `not_attempted`, `released`, `not_owned`, or `unknown` |
| `recovery_required` | An execution identifier exists whose claim/effects may need reconciliation |

A released claim does not establish that retrying is safe. A missing execution
identifier does not establish that a failed adapter or factory was side-effect
free. Inspect correlated evidence before a new request; every new request still
requires current authorization and claim checks. No automatic retry or replay is
introduced. These exception guarantees currently cover `execute()`; other host
operations and a durable manual-recovery interface remain separate work.

## Lazy command runtime initialization

`LazyAgentCommandProcessor` must keep construction lazy and separate preparation
from execution. Its factory and ready/unavailable action observers cannot invoke
nested lazy runtimes, even when another instance already has a cached processor.
These calls return `invalid` with `agent_command_runtime_reentry_denied` and the
host decision `deny`; they do not publish another availability notification.
The process-local preparation fence releases before normal command delegation
and after failed initialization. Later explicit requests can try initialization
again; no automatic retry, queue, distributed lock or provider recovery is added.

Ready and unavailable payloads retain their existing native DevElation contract.
Observer exceptions cannot replace the settled construction outcome. Exceptions
from actual command execution remain execution failures, not unavailable-runtime
reports. Existing authorization/approval processing still owns execution policy.
Lazy construction does not make the required Wise installation optional.
This fence is not a sandbox for arbitrary PHP callbacks or direct access to an
inner processor. Normal command execution may still delegate through another lazy
runtime after preparation has finished; existing execution policy remains in force.

## Evidence and remaining scope

The composition tests must cover read-only lazy inspection, denied probes,
tenant separation, unavailable cached instances, authorized cached cleanup,
denied cleanup, unavailable uncached cleanup, probe exceptions, explicit
recovery, preserved lifecycle state, correlation and exception redaction.
Run `php vendor/phpunit/phpunit/phpunit --filter AgentCompositionServiceTest`
and the full suite before promotion.
Delegation exception regressions run through the locked Automata orchestrator
and its completion hook. Run
`php vendor/phpunit/phpunit/phpunit --filter AgentDelegationServiceTest` to
verify redaction, correlation, no automatic replay and explicit recovery.
Execution persistence regressions inject read/write failures before and after
simulated commits, including failures after an effect. They verify one invocation,
one completion/cleanup attempt, retained identifiers, claim fencing, and bounded
diagnostics. These in-memory fault fixtures do not prove database crash durability.

The injected runtime factory remains host-owned; no production provider is bound
by these fixtures. Existing Wise lifecycle administration is documented in
`runtime-commands.md`; it is not the complete recovery plane. #125 still requires
equivalent authorized web/recovery surfaces, provider/transport adapters, bounded
retry and replay policy, dead-letter/manual recovery controls, fallback
configuration, durable activity/cost metrics and installed-host proof. No
inspection result or source test proves those remaining requirements.

Initialization regressions use native Wise results and DevElation actions,
including native Application resolution, same/cross-instance factory reentry,
cached ready observers, unavailable observers and explicit later recovery. Run
`php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter 'RuntimeInitializationIsolationTest|LazyAgentCommandProcessorTest|CommandHostTest|CommandResultObservationTest'`
and the full suite before promotion. These are offline host fixtures, not provider
or deployment qualification.
