# Opus Extension Points

Opus publishes stable DevElation filters and actions for bounded application
customization. The machine-readable contract is
[`extension-points.json`](extension-points.json). Its `catalog_version` changes
when a public name, payload, return shape, ordering rule, mutability rule, or
failure policy changes.

Filters transform a documented value before the owning service continues.
Actions observe a staged or completed lifecycle moment. Neither mechanism is
authorization, approval, dependency injection, or evidence that work
completed. The owning service reapplies tenant, role, capability, lifecycle,
privacy, review, and execution invariants after filters where required.

## Published Catalog

| Name | Kind | Phase | Failure policy |
| --- | --- | --- | --- |
| `opus.agent.runtime.result` | action | After a correlated composition result | Ignore observer failure; preserve runtime result |
| `opus.addon.boot.result` | action | After a canonical registration outcome settles | Ignore observer failure; preserve boot result |
| `opus.command.result` | action | After shared command presentation settles | Ignore observer failure; preserve command result |
| `opus.addon.organization.changed` | action | After persistence | Ignore observer failure |
| `opus.intake.defaults` | filter | Before session creation | Propagate before persistence |
| `opus.intake.session.transitioned` | action | After persistence | Propagate without rolling back the completed write |
| `opus.agent.command_context` | filter | After authoritative context resolution | Propagate before execution |
| `opus.agent.command_runtime.ready` | action | After runtime construction | Ignore observer failure |
| `opus.agent.command_runtime.unavailable` | action | After runtime construction failure | Ignore observer failure |
| `opus.conversation.settings` | filter | After settings-layer composition | Propagate before use |
| `opus.conversation.configuration` | filter | After scoped configuration composition | Propagate before use |
| `opus.frontend.markup_directory` | filter | Before frontend theme registration | Propagate before use |

Lower numeric DevElation priorities run first; handlers at the same priority
run in registration order. Extensions must not depend on undocumented internal
objects or mutate payload values after a filter returns. Invalid filter returns
follow the deterministic fallback or fail-closed behavior documented in the
JSON catalog.

## Compatibility

Catalog 1.5.0 adds `opus.agent.runtime.result`. Its version-1 payload contains
only `version`, `operation`, `status`, and `ok`. Operation is one of `inspect`,
`start`, `suspend`, `resume`, `stop`, `cancel`, `execute`, `registerRuntime`,
`unregister`, `retire` (added in catalog 1.6.0), or `unknown`. Status is `completed`, `denied`, `failed`,
`unavailable`, or `unknown`; only `completed` with a true runtime outcome reports
`ok=true`, without changing the original result. State, identities, tasks, outputs, diagnostics,
trace, correlation tokens and metadata are excluded.

Delivery occurs after the public operation constructs its correlated result.
It does not assert that remote effects completed, that a superseded transition
was persisted, or that an unavailable operation can be replayed. Idempotent
requests can generate another observation. Descriptor discovery is separate
and does not emit this runtime action. Handlers require enabled DevElation.
Their return values are ignored; an exception stops later handlers in that
delivery but cannot replace the result. During delivery, nested composition
operations and descriptor registration across service instances are denied
before state access or runtime construction, without recursive notification.
The guard resets even after failure. Installed PHP handlers remain trusted
code; this boundary is not a general execution sandbox or durable audit stream.

- Additive optional payload fields may be introduced in a compatible catalog
  release.
- Required-field, type, phase, ordering, mutability, and exception-policy
  changes require a new catalog version and migration notes.
- Renamed extension points require a documented deprecation period; silent
  aliases are not supported.
- Secrets, provider payloads, raw proofs, binary bodies, and unrelated tenant
  data are excluded from extension payloads.

The boundary inventory in the JSON catalog also records areas that are
intentionally closed or owned by stronger service, lifecycle, behavior, or
package abstractions. A method is not extensible merely because an adjacent
area publishes a hook.

The frontend markup directory filter receives a relative directory beneath the
host `resource/` root and must return a nonempty contained relative directory.
It can redirect host theme lookup at registration time, but cannot change the
package-owned admin theme or bypass required Vibe-template checks. The default
comes from `OPUS_HOST_MARKUP_DIRECTORY` in global settings.

## Lifecycle authority in command context (catalog 1.0.1)

`opus.agent.command_context` may enrich context within the documented actor and
tenant constraints. Its `active_addons` and `addon_states` fields are read-only
snapshots: the host restores them from its activated-add-on query after the
filter runs. A missing or failed query leaves both fields empty; a filter cannot
turn that unavailable state into activation. Continuations reread current state,
so prior context and filters cannot revive revoked add-ons.

Extensions that previously replaced these fields must use the authorized add-on
lifecycle service to change activation. Use capability and profile policy for
access restrictions; changing a context snapshot is not a lifecycle operation.
Benign metadata enrichment and the existing actor, tenant, and profile validation
remain supported. This patch adds no hook names and changes no payload types.

A command-context lifecycle refresh that throws while normalizing records denies
all add-on activation for that refresh, including records already read. Filters
observe that empty authority snapshot and cannot restore prior or partial state.


### Add-on organization changes

`opus.addon.organization.changed` observes a committed scoped organization write.
The payload is `application_id` (string), `tenant_id` (string or null), `revision`
(integer), and `action` (string). It omits actor, group, and tag contents. State and
audit are already durable; observer failure is ignored and cannot grant authority
or roll back the write. See [Add-on organization](addon-organization.md) for the
policy, persistence, and remaining transport integration contracts.



### Conversation policy denials

Neither conversation filter may re-enable unknown-intent capture denied by package,
application, tenant, principal, or an earlier filter. Scoped fallback candidate
hints follow the final decision. Trusted-artifact requirements, disabled serialized
learning state, and scope isolation are restored after configuration filters. See
[Conversational learning policy](conversational-learning-policy.md) for the exact
composition rules and remaining runtime integration requirements.

## Canonical boot results (catalog 1.3.0)

`opus.addon.boot.result` delivers the version 1 `bootReport()` snapshot once for
an engine's canonical queued registration after it becomes `registered`,
`skipped`, or `failed`. Success has registered themes/contributions already
available; failure has cleared contributions and settled required/optional
isolation policy. A required failure still throws its original sanitized
`AddOnBootException` after observation.

The array contains `version`, `identity`, `state`, `phase`, `reason`, `effects`,
`retryable`, `required`, and `isolated`. Identity contains only the persisted
`id`, `name`, `namespace`, and `version` strings. Reason is a stable diagnostic
string for failed/skipped attempts or null for success. No paths, configuration,
contribution bodies, provider data, exception objects or private exception text
are passed to observers. The host record is not passed by reference.

Register handlers before the boot event and enable DevElation through the host's
normal configuration. Priorities ascend; equal-priority handlers retain their
registration order. Return values are ignored. A throwing handler stops later
handlers for that delivery under the installed DevElation dispatcher; the host
ignores that observer error without altering success, isolation or the original
failure. Reentrant/repeated boot events, later subscriptions, file repairs and
subsequent dispatcher activation do not replay the result. A new engine has its
own registration attempt.

This is process-local observation, not durable audit delivery or proof that an
optional package became healthy. It adds no lifecycle mutation, retry, authority,
provider access, or administrative transport. Legacy imperative entrypoints and
failures before canonical registration is queued remain outside this hook.
Extensions are trusted application code responsible for their own side effects;
this data boundary is not a PHP sandbox.

This additive catalog release changes no existing hook name or payload. See
[Add-on conventions](../ADDONS.md) for boot policy and report interpretation.
# Shared command results (catalog 1.4.0)

`opus.command.result` receives a version 1 array after `CommandHost` has settled
the presentation returned to the caller. It applies equally to execute, resume
and CLI calls using that host. The fields are `version`, `status`,
`confirmation_required`, `host_failure` and `phase`. Known statuses are
`completed`, `parsed`, `confirmation_required`, `invalid` and `failed`; any other
processor status becomes `unknown` in the observation only. The caller's result
is unchanged. Phases are `presented`, `processing_failed` and
`presentation_failed`; the latter two use private-safe host failures.

The summary omits command names/arguments, output, metadata, tenant/actor data,
diagnostics and continuation tokens. It supports aggregate operation-state
observation, not per-tenant reporting or billing attribution. Use authorized
service interfaces when richer scoped data is required. A completed status is
the processor's report, not an independent proof that external effects settled.

Handlers run in ascending priority and registration order. Their return values
are ignored; exceptions stop later handlers for that delivery but cannot replace
the result or trigger retry. There is no durable delivery/replay guarantee.
While synchronous observation is running, calls through any `CommandHost` fail
before processor dispatch or continuation consumption, report
`command_result_observer_reentry_denied` and emit no recursive observation.
The process-wide guard resets even if a handler throws. Handlers should remain
short and synchronous; a yielding handler retains the guard until it returns.
This guard does not sandbox arbitrary installed PHP or other service APIs.

With DevElation disabled or no handlers, ordinary commands retain their existing
results. No new installation or activation setting is required. Existing hooks
retain their names and schemas; the catalog minor version adds this new action.
