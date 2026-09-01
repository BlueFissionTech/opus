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
| `opus.intake.defaults` | filter | Before session creation | Propagate before persistence |
| `opus.intake.session.transitioned` | action | After persistence | Propagate without rolling back the completed write |
| `opus.agent.command_context` | filter | After authoritative context resolution | Propagate before execution |
| `opus.agent.command_runtime.ready` | action | After runtime construction | Ignore observer failure |
| `opus.agent.command_runtime.unavailable` | action | After runtime construction failure | Ignore observer failure |
| `opus.conversation.settings` | filter | After settings-layer composition | Propagate before use |
| `opus.conversation.configuration` | filter | After scoped configuration composition | Propagate before use |

Lower numeric DevElation priorities run first; handlers at the same priority
run in registration order. Extensions must not depend on undocumented internal
objects or mutate payload values after a filter returns. Invalid filter returns
follow the deterministic fallback or fail-closed behavior documented in the
JSON catalog.

## Compatibility

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
