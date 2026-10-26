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

Authentication must also retain its concrete application owner. Both platform
and minimal-profile registration must bind a lazy Auth resolver through native
`bindArgs()` to that application's container. Neither the unrelated base
Application nor a newer active Engine may supply another application's identity.
Resolving Auth on the base Application loses the host's session/storage bindings;
resolving on the latest Engine can select the wrong host entirely. An unbound
gateway must fail closed rather than infer an owner from global state.

Injected resolvers remain supported. Registration and gateway construction must
not resolve Auth eagerly. Resolution/read/write failures must deny before the
protected controller executes. Regression evidence must cover both gateway names,
both registration paths, opposite authentication states on two real Engines,
owner rebinding/revocation, and absence of effects on the other Engine.

Custom hosts using these gateways must provide the same explicit lazy binding:

```php
$app->bindArgs(
    ['authResolver' => static fn () => $app->resolve(\BlueFission\BlueCore\Auth::class)],
    \App\Business\Gateways\AuthenticationGateway::class
);
```

Native inherited argument resolution supplies this binding to the admin gateway
as well. A deliberately custom resolver remains trusted host code; request data
must never supply it. A standalone no-argument gateway is now a denial boundary,
not an implicit connection to whichever Engine was created last.

## Dependency gate

The currently locked DevElation 1.3.47 does not provide this context API. The
source repair is therefore not deployable with that lock. DevElation's native
registration/Loader repair and BlueCore's bootstrap integration must be reviewed
and adopted explicitly before this consumer change can land. A source-candidate
test is compatibility evidence, not proof that a released or installed dependency
has the repair. Optional identity-bridge and storage contracts remain separate.
