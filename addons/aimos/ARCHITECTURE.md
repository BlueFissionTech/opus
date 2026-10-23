# Aimos native capability audit

Audit date: 2026-10-23. Installed host lock:
Automata `1941852c0625aaf8f6cfc47ac6b5823c9a2724da` (alpha.8),
DevElation `4b9079b8b34010ddd5e0f92774ae9abafbe9ea77` (1.3.47),
Wise `ab4975fd85f8dff9d3f79337b4a3c6fab2e7f855` (alpha.3),
BlueCore `99b1789d162adae71320762917802fae57bd1667` (alpha.4).

| Responsibility | Native evidence | Reuse / boundary |
| --- | --- | --- |
| Goal tree and relationships | Automata Goal/Initiative.php; InitiativeTest.php | Native addChild/parent/children are authoritative, not a second graph format. Validate cycles, shared nodes and bounded size before reading observations. |
| KPI and trigger records | Goal/Kpi.php, Goal/Task.php, InitiativeObject.php | Native Obj-backed records. ReportingInitiative only exposes the existing protected KPI/task collections; remove these accessors when upstream offers public equivalents. |
| Target and cascading state | Goal/Condition.php, GoalManager.php, ManagesGoals.php; GoalManagerTest.php | Native numeric conditions and GoalManager determine satisfaction; child status becomes an explicit native condition. GoalManager latches satisfaction, so use fresh evaluation initiatives each report, never carry satisfaction into recalculation. |
| Observations | Feedback/Observation.php, FeedbackItem.php; examples/generic/disaster_response/initiative_feedback_loop.php | Use native timestamp and Context. Data adapter is host-injected and scope-bound. Do not copy Feedback/Assessor or use fuzzy matching to certify KPI arithmetic. |
| Deterministic metric | DevElation Num and Arr | Aimos owns the explicit sum.v1 reporting formula, provenance, period/unit/freshness/quality validation and bounded aggregation. No expression evaluator, SQL or general analytics engine. |
| Command delivery | Wise Cmd/CommandProcessor.php; DevElation Services/Application.php | Native get behavior, delegate/register/resolve. No parallel command processor or provider startup during catalog discovery. |
| Authorization | Opus AgentRuntimeContext | Fresh authenticated host authority; application, tenant, actor, capability and active lifecycle. Recheck after injected callbacks; never accept scope from command arguments. |
| Trigger effects / persistence | No qualified durable effect adapter in this slice | Native Task records remain disabled. Reads never execute triggers. Even a management grant cannot enable effects before an approved durable/idempotent adapter is supplied. |

Read-only reports are computed from fresh native objects and observations. Only
bounded scalar metadata and aggregate values leave the service. Hooks and injected
PHP callbacks are trusted host code, not sandboxed tenants. The host must provide
authenticated context and isolated adapter credentials. No provider, scheduler,
storage, tenant or dependency is automatically created.

This is the executable reporting slice of #79, not full issue completion. Durable
target updates, approved idempotent trigger execution, audit/scheduling, provider
data qualification and service promotion remain explicit follow-on gates.
