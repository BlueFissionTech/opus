# Runtime availability and lifecycle

The `AgentCompositionService` availability boundary belongs to the application
host. Factories own the implementation of their local availability checks;
runtime/provider packages own transport health, retry algorithms and domain
recovery. This is the first implementation increment for #125, not a complete
operator recovery plane.

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

## Evidence and remaining scope

The composition tests must cover read-only lazy inspection, denied probes,
tenant separation, unavailable cached instances, authorized cached cleanup,
denied cleanup, unavailable uncached cleanup, probe exceptions, explicit
recovery, preserved lifecycle state, correlation and exception redaction.
Run `php vendor/phpunit/phpunit/phpunit --filter AgentCompositionServiceTest`
and the full suite before promotion.

The injected runtime factory remains host-owned; this increment does not bind a
production provider or add web/Wise administration routes. #125 still requires
those equivalent authorized surfaces, provider/transport adapters, bounded
retry and replay policy, dead-letter/manual recovery controls, fallback
configuration, durable activity/cost metrics and installed-host proof. No
inspection result or source test proves those remaining requirements.
