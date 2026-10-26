# Add-on route registration context

## Contract

Canonical add-on factories are queued against a concrete Engine. When its
`OnAppLoaded` event consumes a factory, every registration call must target that
captured Engine, even if another Engine has since become the active default.
Repeated events do not replay a settled factory. A failed registration remains
failed and may have partial effects; restoring a context does not roll them back.

BlueCore owns the context for Engine bootstrap and native mapping discovery.
Opus owns the narrower deferred factory boundary, including direct lifecycle
dispatch outside bootstrap. It uses DevElation's
`Mapping::withApplication($engine, $callback)` rather than copying route tables,
aliasing Application instances or implementing a separate router. Scope exits
before settled-result observers run, restoring the caller's previous context.
Native scopes also isolate suspended Fibers. Explicit Application arguments on
Mapping calls retain the upstream precedence contract.

Acceptance covers real generated factories, direct lifecycle events, independent
Engines, delayed dispatch after a new Engine is constructed, partial failures,
restoration of a surrounding registration context and suspended execution.
Existing HTTP checks retain authentication, CSRF, readiness and route-exclusion
assertions without a special test-only routing path.

The authentication gateway's default resolver also uses the Engine's native
container, where host registration binds Auth's session and storage dependencies.
Resolving Auth on the unrelated base Application loses those bindings and denies
valid sessions. Injected resolvers remain supported, and resolution/read/write
failures still fail closed before a protected controller executes.

## Dependency gate

The currently locked DevElation 1.3.47 does not provide this context API. The
source repair is therefore not deployable with that lock. DevElation's native
registration/Loader repair and BlueCore's bootstrap integration must be reviewed
and adopted explicitly before this consumer change can land. A source-candidate
test is compatibility evidence, not proof that a released or installed dependency
has the repair. Optional identity-bridge and storage contracts remain separate.
