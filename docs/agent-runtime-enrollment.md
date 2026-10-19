# Runtime registration and unregistration

`registerMap()` discovers configured descriptors without creating runtimes.
Runtime enrollment is scoped separately by application, tenant and agent.
The same current authorization and lifecycle policy applies to enrollment as
to other composition operations.

```php
$result = $agents->unregister($agentId, $trustedContext);
// Check the structured result before removing the host or add-on configuration.
```

Unregistration stops an active or failed runtime through its existing `stop()`
contract. After successful cleanup and a committed lifecycle transition, Opus
retains an `unregistered` state and evicts that local cached runtime. A configured
runtime that has never started can be unregistered without a provider probe or
construction. A stopped runtime needs no repeated stop. Other tenant runtimes
remain enrolled. Repeating a completed unregistration is idempotent.

A cleanup failure retains the cached runtime for an authorized retry. An
unavailable runtime does not establish successful cleanup. State-store failures
or superseded transitions must be reconciled before retry; they are not evidence
that remote effects were undone. Records and audit attribution are retained.

Starting an unregistered runtime returns `agent_registration_required`, even
after reconstructing the host and rediscovering its descriptors. Explicitly
call `registerRuntime($agentId, $trustedContext)` before `start()`. Registration
changes metadata only: it neither constructs nor starts a provider. Registration
is idempotent in the registered state and denied while already running, suspended
or failed; it is not a shortcut around stop or recovery.

Successful registration and unregistration rotate a persisted registration ID.
An existing host cache bound to an earlier generation returns
`agent_runtime_registration_stale` and cannot operate on the new enrollment.
Execution admission compares the registration ID again atomically, including
when factory construction runs intervening lifecycle work.

Changing a descriptor while any local runtime scope remains cached returns
`agent_runtime_unregistration_required` before changing any descriptors or the
resolver. Drain and unregister all local scopes before applying that change.
An unchanged descriptor can still be rediscovered without disrupting runtimes.

## Host responsibilities and limits

Unregister while the operator still has the required add-on activation and
capability context. Removing authorization first does not grant a cleanup bypass.
Runtime factories own the meaning of successful `stop()` and its resource cleanup.
This API does not add an upstream agent-module teardown contract or certify that
a remote process has terminated.

For a distributed installation, coordinate admission drain and cleanup across
every runtime owner before replacing descriptors or registering a new generation.
Generation checks prevent stale local reuse; they do not send remote shutdowns,
discard another host's resource handles, or prove distributed quiescence. A stale
host must reconcile its owned resources before rebuilding its composition.

Descriptors remain configured after scoped unregistration so other tenants and
future authorized enrollment continue working. Package removal and global
descriptor/catalog removal are separate host lifecycle responsibilities.
