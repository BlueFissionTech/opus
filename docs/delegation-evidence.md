# Delegation result evidence

`AgentDelegationService` uses the installed Automata hierarchical orchestrator.
Each `worker_results` entry represents the principal planning attempt or an
actually selected specialist. Unselected specialists contribute no measurements.
Business output and execution status keep their existing contracts.

The runtime supplies an optional `metadata.confidence`: a native finite number
between zero and one. The delegation result retains that score independently
of execution status. Missing, null, malformed and out-of-range scores become
null. The delegation-wide `metadata.confidence` is Automata's rounded arithmetic
mean only when every executed participant has a valid score; otherwise it is
null. This is a producer-reported score, not calibrated correctness or permission
to act. Existing consumers must handle null instead of treating success as 1.0.
The installed Automata aggregator casts null to zero. Opus registers
`AgentDelegationPattern` through the native orchestration-pattern extension
point. It extends `HierarchicalPattern`, retains its execution/merge behavior,
and returns unknown before averaging when score coverage is incomplete.
Known-score aggregation still delegates to the parent implementation. Both
global completion observers and scoped callers therefore receive the same
confidence semantics. No fallback is configured by this bridge.

Each worker's metadata carries:

- `agent_id`, `application_id`, `tenant_id`, `correlation_id` and `execution_id`
  for attribution. Scope comes from the host context; execution identity comes
  from the composition result. A pre-execution denial has no execution ID.
- `status`: the runtime's reported outcome, separate from aggregate status.
- `usage`: the reported subset of `cost`, `latency_ms`, `energy` and `invocations`,
  or null when none is usable. Values must be native finite nonnegative numbers;
  latency and invocation counts must be integers. Zero is retained when actually
  reported. Missing fields are not filled with defaults. Invalid fields are
  omitted and produce a diagnostic.
- `trace`: structured runtime events projected to `operation`, `status` and an
  optional nonnegative integer `attempt_count`. Operation names are bounded
  identifiers and statuses are completed, denied, failed or unavailable.
  Malformed events are omitted with a diagnostic.
- Existing diagnostic codes.

Arbitrary runtime metadata and extra trace fields are not copied. In particular,
provider responses, prompts, endpoints, credentials and internal reasoning are
not part of this projection. Trusted runtime adapters must supply appropriate
public identifiers and diagnostics; this projection is not a free-text secret
detector. The added usage, trace and scope fields are not broadcast through
global hooks.
The bridge holds this evidence in a call-local collection and attaches it only
after Automata returns, because the installed orchestrator broadcasts its result
through a global completion hook. Existing upstream business-output hook behavior
is unchanged; the new usage, trace and attribution fields are not added there.
Worker confidence retains its existing upstream hook field, now with an explicit
unknown value when no valid score was reported.

If orchestration or its observer throws after execution, business outputs remain
redacted and `worker_results` is empty. The safe receipts collected so far are
returned in `metadata.execution_evidence`, including each runtime's reported
status. This indicates observed attempts, not rollback, complete orchestration
or permission to retry. Successful aggregate returns carry those receipts on
workers instead; they do not duplicate a second accounting list.
The same fallback carries receipts omitted when Automata catches a pattern
exception and returns a failed result without worker records. Only receipts
not represented by a worker are included in `metadata.execution_evidence`.

Usage remains attributed to each execution, including failed executions that
report usage. It is not automatically summed: currency, energy units, nested
inclusive/exclusive accounting and durable receipt deduplication are not yet
established. A missing measurement does not prove a free operation. Existing
upstream zero defaults are preserved as reported values, not verified metering.
Durable accounting and billing remain separate contracts.
