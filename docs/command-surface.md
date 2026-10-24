# Command surfaces and failure contracts

Opus uses Wise's `ICommandProcessor` for shared command parsing, policy,
confirmation and execution. `CommandHost` adapts its results for programmatic,
CLI and terminal callers through `CommandResultPresenter`. The host does not
create a second command executor or infer authority from command text.

## Current entry points

| Entry point | Execution and presentation | Current boundary |
| --- | --- | --- |
| `terminal` with `cmd i` | Engine console mapping → `CliManager` → `CommandHost` → Wise processor | Interactive text; host context and processor policy govern commands |
| `terminal` with `cmd t` | `CliManager::chat()` → BotMan driver | Conversation surface; not a replacement for command authorization |
| `CommandHost::process/execute/resume` | Wise requests and results → `CommandPresentation` | Shared in-process command contract |
| `App\Terminal` | `TerminalSessions` → connection-scoped `CommandHost` | Fresh trusted identity, connection-owned approval and bounded frames |
| `terminal` with `addon` behaviors | Console add-on manager → lifecycle/readiness services | Structured lifecycle outcomes; legacy Engine dispatch |
| `terminal` with `database` behaviors | Console database manager → BlueCore datasource manager | Legacy migration/population operations; not the shared Wise command path |
| `terminal` with `user` behaviors | Console user manager → user/credential models | Legacy interactive account operations; not the shared Wise command path |
| `terminal` with `contract` behaviors | Runtime contract proof service | Textual readiness/validation guidance |
| `bin/opus-addon.php` | Scaffold generator or passive contract validator | JSON result; 0 success, 1 rejected, 2 invalid usage |
| `bin/audit-installed-dependencies.php` | Installed/locked dependency metadata audit | JSON result and command-specific exit code; no provider calls |
| `bin/audit-composer-vcs.php` | Root Composer registry audit | Text diagnostics and exit code |
| `bin/lint-php.php` | Repository PHP syntax scan | Text diagnostics and exit code |

`mapping/console.php` owns the registered legacy console behaviors. The commented
code-generator registrations are not active. The historical `SocketManager` is
not the current `App\Terminal`/`TerminalSessions` host.

## Shared result contract

`CommandPresentation` retains status, original output, rendered output text,
description, confirmation requirement, exit code, diagnostics, metadata and an
optional continuation token. These concerns remain separate: the processor owns
validation and execution; the presenter renders a result without executing the
command again; the CLI and transport decide how to deliver it.

| Outcome | Shared behavior |
| --- | --- |
| Completed, parsed or provider-declared failed result | Preserve the provider's status, output, exit code and diagnostics |
| Invalid request returned by the processor | Preserve validation diagnostics and nonzero exit code |
| Pending confirmation | Preserve the continuation token; do not execute a second time to render it |
| Optional runtime unavailable | `LazyAgentCommandProcessor` returns its existing invalid/deny result |
| Unexpected processor exception | Failed presentation, exit code 1, `command_host_processing_failed` |
| Unexpected rendering or JSON encoding failure | Failed presentation, exit code 1, `command_host_presentation_failed` |

Unexpected failures contain no exception message, partial output, provider
metadata or continuation token. Their metadata contains `failure_stage` and
`effects: unknown`. The host invokes the processor once and never retries it.
Failure does not prove that no operation occurred or that effects were rolled
back. Inspect the operation before initiating a separate retry.

`CommandPresentation::failedAtHost()` identifies these exceptional outcomes
inside the host. Its origin marker is separate from provider metadata and is not
inferred from a diagnostic string. A provider-declared failed result remains an
ordinary result even if its metadata contains similarly named fields. This is a
trusted in-process distinction, not an authentication token or a wire protocol.

## Authority and terminal behavior

CLI callers obtain context from `AgentCommandContextProvider`; explicit contexts
are a trusted host integration API. Do not accept them from command text or client
frames. Permission checks, add-on activation and approval continuation remain in
the shared processor and its host-owned policy boundary.

Terminal sessions continue to close on unexpected execution/presentation failure,
using the existing `terminal_execution_failed` error frame. Pending confirmation
ownership is consumed before invoking the processor. A failed continuation cannot
be replayed on the closed session. Ordinary provider failures remain ordinary
result frames, and do not become transport failures merely because their metadata
resembles a host failure. Identity changes and revoked authority still close the
session before execution.

## Migration and remaining normalization

### Interactive input integrity

`CliManager::cmd()` and its in-process `interact(Ref $input, Ref $output, Ref $error)`
stream boundary now read complete physical lines through native DevElation handles.
One physical line is one command, never a series of 1023-byte fragments. Blank
lines are ignored; `.` on its own ends the loop. LF/CRLF and an unterminated final
line at EOF are supported. An incomplete nonblocking read fails closed rather
than executing a prefix. Command text is still parsed by Wise, not by this loop.

Commands allow up to 65,536 bytes and confirmation responses up to 128 bytes,
excluding the line delimiter. Limits apply before whitespace trimming and count
UTF-8 bytes, not characters. Over-limit input ends the session with exit code 2
and `cli_input_too_long`; no prefix, suffix or later line is executed. Restart the
session and submit a bounded command rather than relying on truncated execution.

Only a complete `y` or `yes` response (case-insensitive, surrounding whitespace
ignored) approves a continuation. Any other complete answer declines it. EOF or
a failed/oversized confirmation read sends one denied continuation to the shared
host and ends the session. Missing or unresolved confirmation tokens stop with
code 2, without treating later text as an approval or a new command. The existing
context provider refreshes host authority on continuation; no token or policy
store is introduced. A prompt is not an authentication mechanism.

Unexpected host execution/presentation failures stop the session without retry;
ordinary command failures retain their output, diagnostics and exit code, and
the interactive loop may continue. Stream failures return code 1 and a generic
`cli_stream_failed` diagnostic when the error stream is usable. Caller-owned
streams remain open. The returned value is the loop's exit code: the legacy
Engine-backed `terminal` entrypoint still needs separate process-exit propagation
work. BotMan chat and legacy user/database managers are not migrated by this fix.

Run `php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter 'CliInputTest|CliInputProcessTest|CommandHostTest|CommandResultObservationTest|AgentRuntimeCommandProcessorTest'`
and the full suite. The real-stdin fixture uses a synthetic processor and native
process/stream primitives; it proves framing, not authenticated deployment.

Callers that caught raw processor or rendering exceptions from `CommandHost`
should now inspect the failed presentation. Callers using the presenter directly
must handle serialization failure or use the shared host. No command form is
renamed by this increment. Existing provider-defined diagnostics are preserved;
this is not blanket sanitization of intentional command output.

Issue #13 remains open for migrating the legacy managers onto the shared command
contract, consistently guarding destructive/environment-changing operations, and
proving the terminal process exit contract. Native administrator enrollment and
role policy must be established before exposing legacy account or database writes
as governor-accessible commands. Do not interpret this inventory or exception
normalization as permission to bypass those gates.

Focused proof uses real host, CLI, presenter and terminal-session classes with
synthetic processors. It covers failures during processing, output and diagnostic
rendering; unencodable JSON; ordinary provider failures; and effects followed by a
failed approval continuation. Fresh database setup and an authenticated websocket
runner remain separate integration proofs.
