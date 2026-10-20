# Runtime administration through Wise

`AgentRuntimeCommandProcessor` adds an opt-in application runtime resource to
the existing Wise `ICommandProcessor` contract. It uses the wrapped Wise parser
for both text and structured commands, then calls `AgentCompositionService`.
`CommandHost`, `CliManager` and authenticated application callers therefore use
the same operation and policy boundary. There is no second text parser or
separate UI executor.

| Command | Composition operation | Required host capability |
| --- | --- | --- |
| `get agent-runtime "principal"` | Inspect local availability | `opus.runtime.inspect` |
| `do agent-runtime "start" "principal"` | Start | `opus.runtime.start` |
| `do agent-runtime "suspend" "principal"` | Suspend | `opus.runtime.suspend` |
| `do agent-runtime "resume" "principal"` | Resume | `opus.runtime.resume` |
| `do agent-runtime "stop" "principal"` | Stop | `opus.runtime.stop` |
| `do agent-runtime "cancel" "principal"` | Cancel the host-tracked execution | `opus.runtime.cancel` |
| `do agent-runtime "unregister" "principal"` | Stop and unregister | `opus.runtime.unregister` |
| `do agent-runtime "register" "principal"` | Explicit re-enrollment | `opus.runtime.register` |
| `do agent-runtime "retire" "principal"` | Retire the stale local instance | `opus.runtime.retire` |

Replace `principal` with a registered descriptor ID. Quote arguments so Wise
does not interpret a name as another resource. `agent-runtime` uses Wise's
existing hyphenated resource syntax. Extra resources/arguments and unsupported
actions are rejected. Cancellation takes its execution scope from the host's
claim record; a caller cannot select a different execution ID.

The structured equivalent of inspection is:

```php
['verb' => 'get', 'resources' => ['agent-runtime'], 'args' => ['principal']]
```

## Trusted host integration

```php
$runtimeCommands = new AgentRuntimeCommandProcessor(
    $wiseProcessor,
    $composition,
    $resolveCurrentRuntimeContext
);
$commandHost = new CommandHost($runtimeCommands, new CommandResultPresenter());
```

The context resolver is a no-argument host callback. It must obtain current
authenticated identity, application, tenant membership, approvals and narrowly
granted capabilities from the current session/service authority. It returns
`AgentRuntimeContext`; absent context denies. Never construct it from command
arguments or client-supplied `CommandRequest::context`. A nonempty actor ID,
explicit application scope and the exact operation capability are required.
Composition then independently enforces descriptor permissions, application and
tenant binding, add-on lifecycle, enrollment and claim ownership.

Wrap this processor inside `AgentScopedCommandProcessor` when governors use it,
retaining the existing outer tool/profile policy. The agent capability map must
explicitly grant `agent-runtime.get` or `agent-runtime.do`, and the fresh runtime
context must still grant the individual operation. Neither grant substitutes
for the other. Do not automatically add these tools to every governor.

No default resolver, authority grant, provider, web route or role mapping is
installed. This is the shared command adapter; production web/terminal binding
still requires the application's authenticated context resolver and operator
policy. A web controller should submit structured input through this same host
instead of calling lifecycle methods through a parallel authorization path.

## Results and recovery limits

Parsing is passive: it resolves no runtime context and probes/constructs no
runtime. Discovery of general commands and unrelated continuations retains the
wrapped processor's behavior. This adapter issues no runtime continuations,
automatic retries, replay or fallback commands. Lifecycle calls execute once
through composition; state transitions and cleanup claims remain there.

Successful operations return Wise `completed`; failed/unavailable/denied
composition results return Wise `failed` with a nonzero exit code. Metadata
retains `runtime_status` and the trusted correlation ID. Authorized unavailable
inspection exposes `available: false` in the composition result, separately from
persisted lifecycle state. Denied composition results have no output payload.
Missing authority returns `invalid` before inspection or construction.

Unexpected context/composition exceptions return a bounded failure with no
exception text or partial result. `operation_started` indicates whether the
composition call began; `effects: unknown` avoids claiming rollback or safe
replay. Arbitrary provider-owned declared diagnostics retain their existing
privacy contract. Operators must reconcile uncertain work before retrying.

Tests use the installed Wise parser/processor, real composition and Storage,
synthetic synchronization and runtime doubles. They prove text/structured/CLI
parity, current grants, revoked cleanup, identity forgery denial and lifecycle
routing. They do not qualify production HTTP authentication, database crash
recovery, remote provider health or distributed cancellation. Those remain
separate acceptance work under #125.
