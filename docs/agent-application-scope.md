# Application-bound agent runtimes

Use an explicit application binding when composing agent runtimes that share
storage or serve multiple installations. The identity comes from trusted,
immutable installation configuration. A request parameter, generated task,
display name, tenant omission or intake suggestion cannot establish that binding.

```php
use App\Business\Services\ApplicationAgentRuntimeStateStore;
use App\Business\Services\AgentCompositionService;
use App\Domain\Agents\AgentApplicationScope;
use App\Domain\Agents\AgentRuntimeContext;

// Resolve these dependencies and identity from trusted host configuration.
$application = new AgentApplicationScope($installedApplicationId);
$applicationStates = new ApplicationAgentRuntimeStateStore($application, $stateStore);
$agents = new AgentCompositionService($capabilityResolver, $runtimeFactory, $applicationStates);
$context = new AgentRuntimeContext(
    tenantId: $verifiedTenantId,
    actor: $verifiedActor,
    capabilities: $currentCapabilities,
    applicationScope: $application
);
```

The identifier is a canonical lowercase machine identifier, at most 128 bytes,
with alphanumeric segments separated by dots, underscores or hyphens. It is
immutable for the installation; changing it selects a different state namespace.
It is an identity, not a secret or a permission grant. Hosts still authenticate
actors and independently supply current tenant, capability and activation policy.

The state adapter namespaces records and operation locks by application while
preserving tenant and agent keys. It delegates storage and synchronization to the
existing implementation and does not add a database or concurrency algorithm.
Nested application bindings are rejected. Composition binds one application per
instance, so cached runtimes cannot be reused by another application's context.
Inspection, start, suspend, resume, stop, cancellation and execution reject
mismatched or absent application identities before reading state or probing a
runtime. Successful composition results include `application_id` metadata.

The native Automata strategy runtime also pins the application identity at
construction, rejects foreign or absent scope and separates routing subjects by
application and tenant. Task payloads cannot replace this identity. Delegation
checks application scope before inspecting descriptors or constructing its
orchestrator, then passes the same typed host context to coordinator and specialists.

## Migration and limits

Existing unbound stores and contexts retain their legacy single-host behavior.
They reject explicit application claims and must not be presented as providing
application isolation. Null tenant within an explicitly bound application denotes
application scope; missing application identity is distinct and denied there.

Scoped stores never fall back to legacy records, claims or approvals. Before
switching an active installation, stop new admission, reconcile in-flight work
and drain the old runtime under the existing policy. Provision the immutable
identity, bind the store and factory composition, then start fresh scoped
lifecycles under current authorization. Do not copy live execution claims or
approvals into the new namespace. Retain old records for authorized review.

The adapter's namespace is not a substitute for database authorization. Custom
state stores must honor their key and synchronization contracts; untrusted PHP
with direct store access is outside this boundary. Tests use actual host storage
and Automata routing with synthetic locks and fixtures; they do not qualify
multi-process MySQL behavior or a production installation migration.

The native state adapter rejects DevElation's explicit `STATUS_FAILED` and
`STATUS_FAILED_INIT` after every read/write, even when fluent IO returns normally.
It raises `agent_runtime_state_read_failed` or `agent_runtime_state_write_failed`
without forwarding native messages or private data. A failed read is not an empty
store or permission to use cached fields. A failed write is not a successful
claim: composition's execution boundary retains unknown effects and does not
dispatch the runtime or automatically clear/retry the potentially applied claim.
The adapter does not reset native status or repair/activate a failed store.

This is explicit-failure handling, not a generic storage success protocol.
Drivers may return no status or fail through a different signal; nonfailure and
legacy no-status behavior remain compatible, not certified durable. Before using
shared persistent state, qualify the chosen native driver, locking, complete
write/error propagation and restart recovery. Do not substitute a string status,
an in-memory test or a successful method return for that evidence.

This increment does not install Cogito, add module teardown or select an
installation identity automatically. Principal-only module admission and lifecycle
acceptance remain required before enabling that integration.

# Coordinator worker selection

`AgentDelegationService` interprets an explicit `workers` list in the
coordinator's structured output. `[]` is a completed coordinator-only operation:
no specialist runs, the output is empty, and `worker_results` contains only the
supervisor. This does not claim that a specialist performed work.

A supplied selection must be a list of IDs from the caller's candidate participant
set. Null, scalar, associative, nested, non-string or unknown IDs fail the entire
selection with `agent_delegation_selection_invalid` before specialist dispatch.
Mixed valid/invalid lists do not partially execute. Duplicate valid IDs execute
once in first-selected order. Omission of `workers` retains the prior default of
the caller's participant set; callers requiring an explicit no-op must supply
the empty list.

The coordinator has already run when selection is validated. Selection failure
does not roll back its effects or authorize automatic retry. Actual execution
still revalidates each specialist's scope, lifecycle and permissions through
composition; selecting an ID grants no new tools or capabilities. Automata owns
the orchestration algorithm; Opus validates its application-owned selection.
