# Tributary package architecture

The package is a host-composed application service. `Project` snapshots JSON-safe
profile, role, member and assignment records. `TaskService` resolves an exact
application/tenant/project key, intersects membership-role grants with current
host capabilities, then creates an immutable `DelegationRequest` receipt.

`TaskCommandProcessor` decorates a Wise `ICommandProcessor`. It uses Wise's native
parser for text and structured commands, and a no-argument host callback for fresh
`AgentRuntimeContext`. Request metadata is never supplied to that callback.
Unrelated commands and continuations remain with the wrapped processor.

`TaskService` retains native DevElation Service initialization and delegation.
Hosts bind collaborators through Application, resolve the service, then delegate
the instance so registered callbacks run in its scope. Native host actions feed
the existing command processor; they do not create a second authorization path.
Registration tests cover constructor injection, callback scope, native action
delivery and fresh role checks before a workflow can start.

Execution uses Synematic's native `Pipeline` with delegation and observation
steps, then the injected `AgentDelegationService` and actual Automata orchestration,
then `AgentCompositionService` for each governor. Tributary does not create a
provider, start a runtime, import a specialist's tool map or execute returned
text. Result data is the existing delegation result plus a bounded assignment
receipt. Core orchestration's worker diagnostics and supplied normalized metadata
are retained; Tributary does not replace the core accounting contract.

The host may supply a factory returning a fresh native Pipeline. Existing steps
are rejected, context is empty and retries are disabled. Private task data,
authority and results remain in the service's captured execution scope; pipeline
values contain only phase markers and are never interpreted as authority.
Failure before delegation reports no effects. Failure after delegation preserves
the settled agent result and adds a bounded `workflow` failure diagnostic; it
does not repeat the operation. These are synchronous workflow steps, not a local
replacement for Synematic scheduling or Automata orchestration.

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
