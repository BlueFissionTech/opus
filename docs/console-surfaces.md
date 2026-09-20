# Console surfaces and terminal transport

Opus command clients share `WiseCommandHost`, `CommandRequest`, and
`CommandResultPresenter`. The WebSocket terminal is a command client; it does
not provide an operating-system shell or launch a child CLI process. Parsing,
tool grants, profile policy, execution, and approval remain in the Wise command
processor and its `AgentScopedCommandProcessor` wrapper.

## Entrypoint inventory

| Surface | Current entrypoint | Responsibility |
| --- | --- | --- |
| Interactive Wise CLI | `php terminal cmd i` | Shared command processor and result presentation |
| Scripted conversation CLI | `php terminal cmd t` | BotMan conversation adapter; not the terminal command protocol |
| User administration | `php terminal user create`, `user passwd` | Legacy user manager; requires separate credential and mutation-policy normalization |
| Database administration | `php terminal database delta`, `database revert`, `database populate auto` | BlueCore datasource manager; `auto` population is noninteractive |
| Add-on administration | `php terminal addon install`, `install-all`, `uninstall`, `activate`, `activate-all`, `deactivate`, `show` | Lifecycle readiness and add-on manager |
| Runtime contracts | `php terminal contract proof`, `targets`, `validate` | Readiness/target reporting; validate describes interpreter validation rather than running it |
| Add-on scaffold | `php bin/opus-addon.php generate` or `validate` | JSON result and process exit status; use the command's usage output for arguments |
| Browser terminal | `php websocket-server.php` | Optional Ratchet listener, authenticated per-connection Wise host |

These are the current mappings, not a claim that every legacy manager already
has the same result envelope or mutation policy. Add-on, database, runtime, and
user manager normalization remains separate work. Code generation methods are
available programmatically; commented legacy console mappings are not commands.

## Deploying the optional terminal

The listener requires a compatible host installation of `cboden/ratchet` and
`OPUS_TERMINAL_BOOTSTRAP` pointing to a trusted PHP file that returns
`App\Business\Services\TerminalSessions`. Neither is enabled implicitly. An
unavailable transport exits 1; missing or invalid bootstrap configuration exits
2. Bootstrap exceptions produce a redacted startup failure and exit 1.

The bootstrap supplies two callbacks:

- `hostFactory($connection, $context): WiseCommandHost` constructs a new host
  for each connection. Compose the real Wise processor with
  `AgentScopedCommandProcessor`, the application's capability map and profile
  policy. Use connection-isolated processor/continuation storage; do not reuse
  a global PHP session or a shared command host between browser connections.
- `contextResolver($connection): ?array` authenticates the upgrade request and
  revalidates the session, membership, roles, and grants on every command or
  confirmation. Reject unknown origins and untrusted forwarding headers. Return
  null when authentication is absent, expired, or revoked. Never take authority
  from command frames or merely trust that a connection reached loopback.

The resolver returns host-owned `actor.id`, `agent_id`, optional `tenant_id`, and
a `wise_profile` with `type: user`, matching `principal_id` and `tenant_id`, and
current roles. IDs must be nonempty strings. The connection binds the original
actor, tenant, and agent; changing any closes the session. Role/grant changes are
passed to the scoped processor immediately. The transport validates this shape;
the host is responsible for proving the identity and enforcing actual policy.

The worker binds `127.0.0.1:8080`. Publish it through an authenticated TLS reverse
proxy with WebSocket upgrade support and origin validation. The browser defaults
to `/terminal` on the current host using `wss` on HTTPS. A host may set a trusted
`data-endpoint` on the terminal element. Do not include credentials in that URL.
The bootstrap is deployment-owned; no sample credentials or permissive
authentication fallback is supplied.

Dispatch is synchronous. Tools must finish within bounded time or return queued
work through the shared command system. The session limit (128 by default) and
frame limit (16,384 bytes) bound admission, not CPU time or Ratchet's allocation
of incoming frames. Apply transport frame/rate limits and process supervision at
the proxy/worker boundary. This change does not claim live transport deployment
or a production asset build in the dependency-free test suite.

## Wire protocol and migration

After authorization, the server sends `{"type":"ready"}`. Send one complete
JSON command frame: `{"type":"command","input":"list commands"}`. The only
other input is `{"type":"confirm","token":"…","approved":true}` (or false).
Extra keys, client context, empty commands, oversized frames, and nonboolean
approval values are rejected before execution.

Results are `{"type":"result","accepted":true,"result":{…}}`, containing the
shared presentation: status, output, output/diagnostic text, exit code, metadata,
and confirmation fields. `accepted` means the frame reached the command host;
it does not imply command success. A transport validation rejection has
`accepted:false`, status `invalid`, and preserves an existing pending approval.
An accepted confirmation consumes transport ownership even when command policy
denies it. Its token must belong to this connection's current pending command;
no other command runs while confirmation is pending. The processor also checks
its own actor/tenant scope and current policy.

Terminal errors use `{"type":"error","code":"…"}` and close the connection.
Exceptions are not sent verbatim. Close/error releases session state, and late
callbacks cannot restore a closed session. Client input is never queued across
connection failures; reconnecting requires new authentication and explicit input.

Replace clients that sent raw strings or used Xterm's AttachAddon with this
protocol. The bundled client accepts complete lines and explicit yes/no approval;
paste does not submit commands. It strips terminal control sequences from remote
output, avoiding terminal escape injection. This is a line-oriented command UI,
not shell emulation. Deploy the rebuilt client and worker together; old clients
are deliberately rejected. CLI spellings above are unchanged.

## Proof

Run `php vendor/phpunit/phpunit/phpunit --filter TerminalSessionsTest`, the agent
capability tests, and `npm run test:assets`. These exercise command forwarding,
actual scoped denial before execution, identity revocation, cross-connection and
replayed approvals, reentrant callbacks, cleanup, frame bounds, client send-once
behavior, and output sanitization. Ratchet network/authentication integration and
the compiled browser bundle must additionally be tested in the deploying host.
