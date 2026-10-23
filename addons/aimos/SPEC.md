# Aimos deterministic goal reporting

Related: #79. A host supplies a native Automata initiative tree, native KPI/Task
records and a scoped observation adapter. A specialist can discover the tree,
query status, explain provenance and request a deterministic report through Wise.

Acceptance for this reporting slice:
- Evaluate sum.v1 from finite numeric observations in a half-open period.
- Preserve source, period, unit, formula version, target, freshness and quality.
- Native conditions determine numeric target satisfaction and cascade child state.
- Missing/stale/invalid data never implies a satisfied target or a zero metric.
- Reject cycles, shared nodes, duplicate identifiers and conflicting targets.
- Recalculate downward as well as upward; do not reuse latched native satisfaction.
- Denied, cross-tenant or revoked reads return no observations or private payloads.
- Discovery is passive; reads do not execute tasks or activate providers.
- A real Application resolves/delegates/registers the service and Wise dispatches it.

No live customer data, target mutation, trigger effects, durable storage, HTTP
hosting or service promotion is included. Trigger mutation requires both explicit
authority and a reviewed durable/idempotent effect contract; the latter is not
available in this slice, so effects remain unavailable rather than simulated.
