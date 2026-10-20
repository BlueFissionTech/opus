# Tributary package architecture

The package is a host-composed application service. `Project` snapshots JSON-safe
profile, role, member and assignment records. `TaskService` resolves an exact
application/tenant/project key, intersects membership-role grants with current
host capabilities, then creates an immutable `DelegationRequest` receipt.

`TaskCommandProcessor` decorates a Wise `ICommandProcessor`. It uses Wise's native
parser for text and structured commands, and a no-argument host callback for fresh
`AgentRuntimeContext`. Request metadata is never supplied to that callback.
Unrelated commands and continuations remain with the wrapped processor.

Execution enters `AgentDelegationService`, then actual Automata orchestration,
then `AgentCompositionService` for each governor. Tributary does not create a
provider, start a runtime, import a specialist's tool map or execute returned
text. Result data is the existing delegation result plus a bounded assignment
receipt. Core orchestration's worker diagnostics are retained; full per-runtime
traces are not currently propagated by that core result contract.

The package owns two DevElation extension points:

| Hook | Contract | Authority |
| --- | --- | --- |
| `tributary.task.input` | Array of JSON-safe task data, depth at most 16 | Cannot replace identity, targets, project, correlation or grants. Invalid output prevents dispatch. |
| `tributary.task.result` | One argument: `{version: 1, status: string}` | No inputs, outputs, names, IDs or scope data. Exceptions are isolated after execution. |

Both hooks run under a process-wide reentry guard. Nested Tributary dispatch is
denied across service instances, including during filtering. The guard is reset
in `finally`. It is neither a cross-process lock nor a sandbox for arbitrary PHP.
Hooks are inactive unless the host enables DevElation. There is no effect replay.

The host must enforce identity, fresh revocation, any per-task human approvals,
runtime enrollment and persistence. A project assignment constrains dispatch;
it is not a separate runtime-memory partition. Hosts must use distinct runtime
identities or a qualified isolation adapter where projects must not share memory.
No concurrent queue, durable cancellation or billing journal is implemented here.
