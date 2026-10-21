# Principal advisory modules

`PrincipalModuleRuntimeFactory` is an opt-in host integration for trusted
Automata `IAgentModule` implementations. It accepts only application-owned
`central` descriptors (the principal governor compatibility identifier) with
an explicit application scope. It does not replace the
[native strategy runtime](native-strategy-runtime.md): general task selection
continues through StrategyRouter and Intelligence. This is a separate advisory
endpoint, not a complete governor harness or automatic cognitive installation.

## Host wiring

Configure the factory in trusted application bootstrap code and inject it into
`AgentCompositionService`, together with the capability-map resolver and an
`ApplicationAgentRuntimeStateStore` bound to that application's identity.
The descriptor's profile must match a configured profile. Neither a task nor an
add-on manifest may supply the factory callback or change its configuration.

```php
$factory = new PrincipalModuleRuntimeFactory([
    'principal-advice' => [
        'enabled' => true,
        'capability_id' => 'principal.advice',
        'capability_version' => '1',
        'input_key' => 'input',
    ],
], $buildTrustedModule, $authorizeCurrentRequest);
```

Both callbacks receive the host `AgentDescriptor` and current
`AgentRuntimeContext`. The module builder returns a fresh `IAgentModule` for
each runtime generation; a module object cannot be shared between runtimes.
Any underlying harness, profile or external client must likewise be isolated
or explicitly safe to share by the host. The builder is trusted installed PHP,
not code obtained from a task. Validate the installed implementation and its
immutable cognitive profile in bootstrap before exposing this endpoint.

The authorization callback additionally receives an Automata
`StrategyRouteRequest` containing the execution/correlation identifiers, input,
configured capability and a subject bound to the principal, application and
tenant. It must return a current `AutonomyDecision`. An allowed decision must
match the subject, capability ID and version exactly. The host must implement
its current role, value-policy and approval checks in this callback; an always
allow callback is not production authorization. Composition independently checks
current capabilities, application scope and runtime enrollment before execution.

Profile configuration is snapshotted. Missing or non-boolean enablement is
disabled. Readiness and startup do not construct a module or Agent, call the
authorization callback, or contact a provider. Every evaluation authorizes
before lazy construction or reuse. Failed authorization does not invoke the
module. Installed module selection and release qualification remain explicit
deployment responsibilities; this feature acquires no dependencies.

## Input, output and effects

Only `task['input']` is mapped into the configured `input_key` (default `input`).
The module also receives host-resolved `applicationId`, `agentId`, `tenantId`,
`correlationId` and `executionId`. Tenant is present even when null. Reserved
identity keys cannot be used as input keys. Task-level identity, profile and
enablement fields are ignored. Validate the module's own input schema at its
boundary; these payloads are not authority.

The runtime constructs the actual Automata `Agent(null)` and calls `runModule`
through `AdvisoryAgentModule`. That wrapper gives the module fresh isolated
`AgentState`, not the retained Agent state. It rejects changed state, proposed
writes and non-null decisions **before** Automata can apply writes to the retained
Agent. It then reconstructs a result containing status and advisory metadata
only. Successful public output is:

```json
{"executionAuthority":"none","reviewRequired":true,"advisoryStatus":"advisory","advice":{}}
```

Only `completed`, `advisory` and `review_required` module statuses yield reports;
none grants execution authority. `advisoryStatus` preserves this distinction.
A completed host evaluation can therefore carry a report requiring human review
with evidence gaps and no recommendation. Advice is untrusted content and must
not authorize tools or effects. Render it
with normal escaping and privacy controls. Incomplete evaluations expose no
module metadata. Exceptions return generic diagnostics and fault the runtime,
preventing silent replay. Usage is explicitly unknown (`usage_known: false`),
not zero cost; complete task accounting needs a separate usage contract.

This is an advisory data boundary, **not a PHP sandbox**. Trusted module code,
constructors and global hooks can perform external effects. The host must admit
only reviewed implementations, restrict available clients and enforce effect
approval outside this endpoint. The isolated state does not promise to detect
temporary mutations that a module reverses, nor external effects.

## Lifecycle and proof

Use the composition service for lifecycle/enrollment; direct runtime calls do
not replace its state machine or permission checks. Stop releases local Agent
and module references; a healthy restart builds a fresh instance. Faulted
instances require removal and explicit re-enrollment, not a silent retry.
Retirement permanently fences the object and releases its callbacks/config.
Evaluation and cleanup reentry are denied. A throwing destructor makes cleanup
uncertain and keeps the object fenced; retry does not manufacture success.
See [runtime enrollment](agent-runtime-enrollment.md) for stale-owner cleanup.

`PrincipalModuleRuntimeTest` exercises the installed Automata Agent with local
callable modules, real composition/storage and synthetic synchronization. It
covers current authorization, disabled/specialist rejection, input mapping,
scope isolation, retained-state integrity, faulting and cleanup. These tests do
not qualify an optional cognitive package, provider calls, distributed drain,
or production effect isolation. A specific module still needs pinned installed
host acceptance with its own schema, profile, effects and lifecycle fixtures.
