# Native strategy runtime

`AutomataStrategyRuntimeFactory` is an opt-in implementation of
`IAgentRuntimeFactory`. It connects `AgentCompositionService` to Automata's
`StrategyRouter`, including an injected `Intelligence` advisor. It does not use
the legacy `IntelligenceService` or its unavailable BlueCore `StrategyManager`.

The host supplies three dependencies:

1. A profile map keyed by the descriptor's profile. Each entry supplies
   `capability_id`, `capability_version`, exact `{id, version}` candidates and
   limits. Optional `allowed_modes`, `selection_policy`, `escalation_policy`
   and `context_key` configure Automata routing. Defaults are deterministic
   mode, declared selection, no escalation and deterministic preference.
2. A router builder `(AgentDescriptor, AgentRuntimeContext): StrategyRouter`.
   Construct a fresh router, advisor and stateful adapters for each runtime;
   do not share mutable routers or provider sessions across tenants. The
   builder runs only on creation, never during availability inspection.
3. A host authorizer
   `(AgentDescriptor, AgentRuntimeContext, StrategyRouteRequest): AutonomyDecision`.
   Re-evaluate current identity, tenant membership, exact grants, expiry,
   revocation, approval and budgets on every call. Automata's
   `AutonomyPacket::authorize()` and capability registry can produce this
   decision from trusted current records. Do not derive approval from task
   content, an LLM response, or a cached previous decision.

```php
$factory = new AutomataStrategyRuntimeFactory(
    ['review' => [
        'capability_id' => 'review',
        'capability_version' => '1',
        'candidates' => [['id' => 'review-tree', 'version' => '1']],
        'limits' => ['max_invocations' => 1],
        'selection_policy' => 'adaptive',
    ]],
    $buildScopedRouter,
    $authorizeCurrentRequest
);
$composition = new AgentCompositionService($resolver, $factory, $stateStore);
```

These injected callbacks and the state store are host responsibilities, not
automatic grants. Register validated capability maps with the composition
service and use its start/execute/suspend/resume/stop methods. Its capability,
add-on, tenant, state and concurrent-operation checks remain authoritative.
The runtime itself adds tenant binding and a subject derived from both agent
identity and tenant scope; the authorizer must authorize that exact subject.
An availability result means a local profile and router class exist; it does
not verify grants, provider health or successful execution.

Tasks supply only `input` to the route. They cannot override subject, candidates,
limits, modes or correlation. The authorizer receives a separate request value.
Automata enforces exact capability/subject authorization, eligibility, budgets
and side-effect-free strategy execution. Its native hooks remain available.
Learned/generative modes and fallback require explicit profile configuration
and eligible adapters; the runtime never falls back directly to an LLM.

Successful results expose adapter business output. Failed results expose no
output. Route status, attempt count, selected strategy identity/mode,
cost/latency/energy/invocation usage and
correlation are returned without raw authority packets, exception messages,
provider diagnostics or arbitrary trace metadata. Hosts must still apply their
normal access and output-redaction policy to successful business output.

This runtime is synchronous. Lifecycle calls acknowledge local admission state;
the composition service persists it. It cannot interrupt an in-flight provider
call and returns `agent_runtime_cancellation_unsupported` for cancellation.
Reentrant execution and lifecycle transitions during execution are denied.
It creates no background workers or Cogito modules and therefore claims no
worker shutdown or module teardown. Those adapters, unregister wiring, Wise
tool integration and production identity/approval configuration remain separate
acceptance work under #29 and the native governor requirements.

Validation uses the locked Automata v1.0.0-alpha.5 APIs with the real router,
Intelligence advisor and decision tree. It needs no provider key or network.
The composition integration test uses an in-memory state-store fixture; it
does not qualify production MySQL concurrency or a deployed governor.
