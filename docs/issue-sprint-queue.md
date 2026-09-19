# Open-issue sprint queue

Snapshot: 2026-09-19. Covers **all 56 open Opus issues** at capture time. The
[JSON queue](issue-sprint-queue.json) retains exact issue identities, source-body
hashes, observed priority, ownership, proof and gates. The inventory was checked
against the canonical repository issues API after the gateway flagged its
fallback result as partial: both returned the same 56 issues. Refresh before
execution; newly opened issues are not silently included in this snapshot.

## Delivery discipline

Each entry is a proposed **next bounded sprint deliverable**, not an epic-sized
implementation commitment. Keep one implementation sprint active; complete its
review, tests, integration smoke, recovery path and observable deployment before
claiming the MVP is deployed. Documentation and contract increments may land
independently, but do not establish feature availability. No row grants deployment,
provider spend, production data access, or a legal/license decision.

Stages are `implementation_staged`, `pr_open`, `planned`, `contract_review`,
`coordination_staged`, `upstream_gated`, `dependency_gated`, and `decision_gated`.
A gate names what must become true; it is not an excuse to duplicate an upstream
implementation. `planned` means a bounded candidate, not completed source audit
or guaranteed readiness. Staging an entire queue does not start all its work.
Parent issues close only after their complete acceptance criteria pass.

Human well-being, agency, accessibility, recourse and sustainable operation are
the governing outcomes. Revenue and adoption are operating constraints. Each MVP
must name who benefits, who bears risk, how humans intervene, and what observable
benefit it demonstrates. Use the same Wise command/policy backbone for UI, CLI,
API and governors; expose actual actions and receipts through clear process
presentation, not invented progress or chat-only interaction. Prefer deterministic
routes and cached workflows before inference. Attribute costs through specialists,
governors and the principal without granting accounting systems new authority.

## Execution order and product corrections

First complete installation, configuration, hooks, command parity and governor
security. Then follow the operator's explicit order: **Pelorus value-based
reasoning → Orchestrate → Vault MVP → Keryx → Axis → Vista → Aidea**. Tenancy,
identity, storage and other necessary structural capabilities enter at point of
need. After reference proof, advance one Arkheion add-on MVP at a time, and only
then promote proven capabilities into paid services. The lanes below organize
work; they are not parallel epics or date commitments. Within a lane, prerequisite
slices can precede consumers; listed rows do not override their gates.

The following operator corrections supersede older issue framing for execution:

- Keryx (#85) belongs to Kyber coordination. Cogito (#76) provides value-led,
  perspective-driven strategic governance, not merely chatbot functionality.
- Principal governor is the product term; retain existing central/specialist
  identifiers until a tested migration. Governors are scoped user principals.
- Strategic Cogito principals retain human oversight. Ordinary Arkheion service
  coordination uses simpler Synthetiq behavior where appropriate. Cross-instance
  coordination is capability-scoped; advisory relationships are not implicit grants.
- Kyber/Jenie's demo → lab → hub cutovers preserve one codebase and live data;
  resettable synthetic tenants remain distinct from retained Vault customer data.
- Structural Arkheion add-ons are platform capabilities before standalone service
  upsells. Vault's user-facing Kyber services remain distinct from infrastructure.
- Within later add-ons, preserve Tributary → Repos → Vija → Seiso → Apropos,
  Cogito → Aimos, and Telaio → Kwikle dependencies. Early tenancy/authentication
  and cost visibility support the reference workflows rather than waiting for a
  standalone service launch.

## Immediate staged increments

1. #110: atomic fail-closed lifecycle authority; a valid record followed by a
   malformed record must not leave tools activated, including continuation.
2. #56/#109: compatible consumer dependency graph and corrected upstream enum;
   the exact-lock runtime pass does not prove a fresh consumer solve.
3. Review existing PRs [#127](https://github.com/BlueFissionTech/opus/pull/127),
   [#131](https://github.com/BlueFissionTech/opus/pull/131), and
   [#136](https://github.com/BlueFissionTech/opus/pull/136). Reported check summaries
   are not a substitute for confirming the exact-head runtime checks and reviews.
4. Complete real lifecycle/command and optional-runtime proof, then the
   tenant/policy/synthetic-host gates needed for the first reference workflow.

## Tracked sprint slices

### Foundation

- **[#110 — Establish stable DevElation hook and filter integration surfaces](https://github.com/BlueFissionTech/opus/issues/110)** · `implementation_staged`
  Owner boundary: Opus. Next: Discard all partial lifecycle authority after a record-normalization failure.
  Proof: Red/green fresh and continuation regressions; scoped capability/hook suites; full exact-lock suite. Gate: Review and merge the isolated fix; remaining hook surfaces stay open.

- **[#56 — Provide a credential-free consumer dependency baseline](https://github.com/BlueFissionTech/opus/issues/56)** · `upstream_gated`
  Owner boundary: Opus; Wise; Vibrato. Next: Publish a compatible consumer manifest candidate using reviewed dependency artifacts.
  Proof: Anonymous required-Wise and optional-Wise fresh solve, exact-lock install, native command and package-layout smoke. Gate: Wise alpha.2 pins DevElation~1.3.43.0 while current Vibrato requires^1.3.44; compatible upstream publication required.

- **[#109 — Correct the Kapsle tenant-status enum autoload contract](https://github.com/BlueFissionTech/opus/issues/109)** · `upstream_gated`
  Owner boundary: Kapsle; Opus integration. Next: Consume a reviewed correction of the duplicate tenant-status enum.
  Proof: Both enum symbols and cases load under optimized authoritative autoload without ambiguity; embedded-host tests. Gate: Kapsle issue2 owns source repair; unchanged lock still reproduces it. Do not patch vendor.

- **[#20 — Provide a verified local Compose recipe for Opus](https://github.com/BlueFissionTech/opus/issues/20)** · `pr_open`
  Owner boundary: Opus; deployment owner. Next: Review PR127 and prove the declared Compose application endpoint.
  Proof: Missing-recipe denial, actual health endpoint, startup/teardown receipt and clean-volume retry. Gate: PR127 is draft; service execution must use approved orchestration and isolated test data.

- **[#130 — Document weekday repository triage automation policy](https://github.com/BlueFissionTech/opus/issues/130)** · `pr_open`
  Owner boundary: Opus. Next: Review and land the weekday triage operating policy in PR131.
  Proof: Diff against operator cadence, single continuing automation, escalation and permission rules. Gate: Policy PR does not prove a scheduler is installed or change its authorization.

- **[#10 — Expand baseline test coverage and CI gates](https://github.com/BlueFissionTech/opus/issues/10)** · `planned`
  Owner boundary: Opus. Next: Add one reproducible PHP syntax, baseline-test and asset-manifest CI entrypoint.
  Proof: Clean checkout and exact lock; optional services absent; negative fixture must fail CI. Gate: Consumer CI depends on#56; distinguish review-bot checks from executed runtime tests.

- **[#104 — Publish a reproducible self-hosted Opus distribution](https://github.com/BlueFissionTech/opus/issues/104)** · `pr_open`
  Owner boundary: Opus; release owner. Next: Review PR136 runtime snapshot, then prove one production-profile installation and recovery path.
  Proof: Source/dist identity, no-dev metadata, startup twice, synthetic upgrade failure and recovery; hashes retained. Gate: PR136 is development-only;#56,#16 and upstream warnings block public release certification.

- **[#107 — Publish the Materia integration conformance proof](https://github.com/BlueFissionTech/opus/issues/107)** · `pr_open`
  Owner boundary: Opus; direct dependency owners. Next: Extend PR136 evidence into one capability-level conformance row and cross-package workflow at a time.
  Proof: Exact package refs, owned boundary, optional status, failure/security behavior, immutable evidence. Gate: 94 matching packages and passing baseline do not prove all capabilities;#56 consumer proof remains blocked.

- **[#16 — Normalize package license metadata](https://github.com/BlueFissionTech/opus/issues/16)** · `decision_gated`
  Owner boundary: Legal/product owner; Opus metadata. Next: Prepare a consistent Composer/README/license patch after the license owner selects legal terms.
  Proof: Strict Composer metadata validation and matching license text. Gate: Current Exclusive value is not SPDX; exact replacement license is a human legal/product decision.

- **[#12 — Add addon lifecycle readiness acceptance tests](https://github.com/BlueFissionTech/opus/issues/12)** · `planned`
  Owner boundary: Opus; BlueCore. Next: Exercise install/activate/deactivate/remove readiness against a real synthetic application lifecycle.
  Proof: Malformed result, partial migration, theme asset, retry and teardown evidence through CLI. Gate: PR133 merged fixture hardening; real lifecycle proof remains coupled to#20 and released BlueCore behavior.

- **[#40 — Align add-on activation hooks with the engine lifecycle](https://github.com/BlueFissionTech/opus/issues/40)** · `planned`
  Owner boundary: Opus; BlueCore. Next: Prove exactly-once registration from actual engine dispatch for one synthetic add-on.
  Proof: Active/inactive fixtures, repeated dispatch, failed registration and teardown; no duplicate route/theme contributions. Gate: Confirm the released engine event contract;#12 supplies readiness evidence.

- **[#43 — Provide a canonical add-on scaffold and contract validator](https://github.com/BlueFissionTech/opus/issues/43)** · `planned`
  Owner boundary: Opus. Next: Generate one add-on and run its passive factory and mappings through the real host lifecycle.
  Proof: Namespace/paths, no partial writes, absent navigation, repeat generation, exactly-once registration. Gate: Build on merged PR133;#40 must prove real dispatch before claiming complete scaffold conformance.

- **[#13 — Normalize backend CLI tool surface](https://github.com/BlueFissionTech/opus/issues/13)** · `planned`
  Owner boundary: Opus; Wise. Next: Inventory executable command entrypoints and normalize one divergent manager through the shared host.
  Proof: CLI/API result parity, same permission decision, single execution, denied/destructive command fixtures. Gate: Use installed Wise contracts; preserve compatibility identifiers and avoid parallel command dispatch.

- **[#61 — Consume the authoritative Wise resource catalog](https://github.com/BlueFissionTech/opus/issues/61)** · `upstream_gated`
  Owner boundary: Wise; Opus. Next: Replace inferred resource mappings only after the authoritative catalog is consumable.
  Proof: Canonical and alias mappings, unavailable capability, missing-required failure and optional atomic skip. Gate: Requires a reviewed Wise catalog release plus#56 compatible consumer graph.


### Governance

- **[#125 — Define optional runtime degradation and recovery contracts](https://github.com/BlueFissionTech/opus/issues/125)** · `planned`
  Owner boundary: Opus; capability owners. Next: Represent runtime availability separately from lifecycle activation for one optional capability.
  Proof: Unavailable provider does not boot/call eagerly or break unrelated routes; bounded cancel/recovery and denial. Gate: Follow#110 invariants; keep package retry mechanics upstream and define stable reason codes first.

- **[#129 — Harden continuity and compaction against persistent prompt injection](https://github.com/BlueFissionTech/opus/issues/129)** · `contract_review`
  Owner boundary: Opus; Wise; Automata; Chronicler; Annex. Next: Freeze hostile continuity fixtures and host control/data separation before enabling historical context.
  Proof: Cross-tenant, authority laundering, replay, concealed failure and data-to-code fixtures all deny safely. Gate: Wise72, Synthetiq50 and Annex SDK27/28 remain upstream contract gates; review before activation.

- **[#128 — Compile verified historical intent into scoped agent execution context](https://github.com/BlueFissionTech/opus/issues/128)** · `upstream_gated`
  Owner boundary: Wise runtime; Opus persistence/policy. Next: Review one immutable non-executable continuity packet and exclusion-reason contract.
  Proof: Stable/superseded/revoked/expired/conflicting records; deterministic compile; no capability widening. Gate: Ownership confirmations and#129 security gate precede host implementation; no duplicate validity engine in Opus.

- **[#99 — Add scoped conversational learning and private Wise profiles](https://github.com/BlueFissionTech/opus/issues/99)** · `planned`
  Owner boundary: Opus; Wise; Synthetiq. Next: Prove one persisted private profile across tenant/principal scopes with explicit deny precedence.
  Proof: Concurrent policy change, delegation/revocation, restart, private-resource denial and seed idempotency. Gate: Existing scoped contracts require real storage evidence; optional learning stays shadow/review-only.

- **[#123 — Complete native user, tenant, role, and policy administration](https://github.com/BlueFissionTech/opus/issues/123)** · `planned`
  Owner boundary: Opus; optional Presence/Hoom adapters. Next: Ship a read-only effective-access inspection service shared by UI, API and Wise.
  Proof: Tenant/actor scope, hidden-resource redaction, explicit denies, unavailable identity adapter; later mutations reauthorize. Gate: Use#99 policy and#110 post-filter invariants; privileged lifecycle/membership writes are later slices.

- **[#124 — Build a unified activity, approval, and explainability timeline](https://github.com/BlueFissionTech/opus/issues/124)** · `planned`
  Owner boundary: Opus; trace owners. Next: Expose one redacted command/approval/result timeline through a read-only shared service.
  Proof: Correlated events, role-filtered view, cross-tenant denial, no secrets, uncertainty and actual terminal receipt. Gate: Preserve detailed package traces upstream; replay is a new authorization decision, not a timeline action by default.

- **[#120 — Organize add-ons by feature, tags, and lifecycle impact](https://github.com/BlueFissionTech/opus/issues/120)** · `planned`
  Owner boundary: Opus. Next: Preview the impact of disabling one add-on on feature groups and authoritative dependencies.
  Proof: Many-to-many membership, explicit dependency denial, degraded groups, tenant filtering and100-entry usability. Gate: Tags never grant capability or satisfy dependencies; mutation/confirmation integrates after#123/#124.

- **[#122 — Define resettable synthetic tenant host conformance](https://github.com/BlueFissionTech/opus/issues/122)** · `dependency_gated`
  Owner boundary: Opus; fixture add-on owner. Next: Run two expiring synthetic tenants through isolated fixture load and repeated reset.
  Proof: Database/cache/files/queues/session isolation; stable checksum; expiry stops work; all outbound effects denied. Gate: Requires#110,#123,#125 and actual test services; hosted promotion additionally needs#104/#107.

- **[#119 — Ship a curated first-party starter add-on profile](https://github.com/BlueFissionTech/opus/issues/119)** · `dependency_gated`
  Owner boundary: Opus; separately versioned starter owners. Next: Offer an inert install preview for MCP bridge, contact intake and Annex starter packages.
  Proof: None/all/subset selection, skip/resume, zero provider/listener/grant at bundle discovery. Gate: Package ownership/releases and#122 synthetic profile proof before activation or marketplace promotion.

- **[#31 — Publish minimal API and authenticated dashboard application profiles](https://github.com/BlueFissionTech/opus/issues/31)** · `dependency_gated`
  Owner boundary: Opus. Next: Ship a minimal API profile with deterministic health/readiness and one protected route.
  Proof: Repeated init, malformed parse-only configuration, absent optional capability and clean-service smoke. Gate: #20,#56 and storage initialization proof; authenticated dashboard follows access-policy readiness.

- **[#95 — Prioritize resumable agent-led application onboarding and control plane](https://github.com/BlueFissionTech/opus/issues/95)** · `planned`
  Owner boundary: Opus. Next: Prove persisted onboarding resume through the same UI/CLI command with no intake side effects.
  Proof: Restart/session parity, immutable slug, partial answers, shadow plan, denied capability and cancellation. Gate: Use existing intake seams;#123 policy and#124 observable execution must gate consequential work.

- **[#29 — Define application and add-on agent composition contract](https://github.com/BlueFissionTech/opus/issues/29)** · `planned`
  Owner boundary: Opus; Automata; Synthetiq; Wise. Next: Run one principal and two specialist governors with injected factories and bounded delegation.
  Proof: Passive discovery, tool isolation, cancellation/retry/teardown, non-transitive grants and cost rollup. Gate: Released orchestration contracts only;#110,#99 and#125 control lifecycle, profile and failure boundaries.


### Kyber

- **[#3 — define blue vision registry and regime framework contract](https://github.com/BlueFissionTech/opus/issues/3)** · `contract_review`
  Owner boundary: Opus generic envelope; Pelorus/Cogito value overlays. Next: Specify the versioned value/regime decision packet without embedding one philosophy in core.
  Proof: Explicit scope/horizon, evidence freshness, alternatives, uncertainty and human override; link Pelorus25. Gate: Value vocabulary and psychograph semantics stay provider-owned; no inferred authority.

- **[#101 — Define a side-effect-free consulting guidance extension](https://github.com/BlueFissionTech/opus/issues/101)** · `planned`
  Owner boundary: Opus advisory boundary; optional provider. Next: Implement one serializable consulting request/outcome with a deterministic injected provider.
  Proof: Missing/stale evidence, expiry, ranking, override and absent provider; zero task/budget/workflow mutation. Gate: Use#3 approved envelope; provider output advises and never approves execution.

- **[#4 — Audit Control Hub lineage for Opus and Materia extraction](https://github.com/BlueFissionTech/opus/issues/4)** · `planned`
  Owner boundary: Opus; responsible Materia owners. Next: Inventory legacy control-surface concepts and map each to retain, retire, upstream or application ownership.
  Proof: Source-backed lineage table and existing upstream issue references; unknowns remain explicit. Gate: Audit only; do not copy legacy host architecture or licensed/private assets.

- **[#85 — Port Keryx as a governed Arkheion add-on](https://github.com/BlueFissionTech/opus/issues/85)** · `dependency_gated`
  Owner boundary: Keryx add-on owner; Opus. Next: Compose a read-only threaded coordination/job-status surface through an injected gateway.
  Proof: Authenticated recipients, scope/redaction, unavailable/stale/denied states, no raw privileged tool exposure. Gate: Operator places Keryx in Kyber; correct legacy Arkheion framing before implementation. Vault follows Orchestrate and precedes this slice.


### Addons

- **[#67 — Stage the Arkheion add-on and service program](https://github.com/BlueFissionTech/opus/issues/67)** · `coordination_staged`
  Owner boundary: Opus; suite/product owners. Next: Maintain one package PRD, next MVP, dependency gate and proof receipt per add-on.
  Proof: Every child has package ownership, inactive discovery, commands/hooks, test evidence and service promotion blockers. Gate: Operator order supersedes older first-wave wording: Kyber reference workflows before independent paid microservices.

- **[#81 — Align Kapsle with the Arkheion add-on contract](https://github.com/BlueFissionTech/opus/issues/81)** · `upstream_gated`
  Owner boundary: Kapsle; Opus integration. Next: Align existing tenancy add-on and prove two-tenant read isolation plus idempotent context initialization.
  Proof: Missing/stale/foreign tenant denial across HTTP/CLI/agent and teardown; no ambient global authority. Gate: #109 upstream enum fix and deterministic database lifecycle release; tenancy remains an early Vault prerequisite.

- **[#86 — Align Hoom with the Arkheion add-on contract](https://github.com/BlueFissionTech/opus/issues/86)** · `dependency_gated`
  Owner boundary: Hoom; Presence; Opus integration. Next: Align existing identity add-on and prove tenant-aware session status and access denial.
  Proof: JWT/JWK, refresh/expiry, denied participant, redacted proof, optional provider and clean teardown. Gate: Preserve existing edits; consume reviewed Presence/BlueCore contracts; real auth integration before voice/video.

- **[#80 — Scaffold the Tributary multi-agent add-on](https://github.com/BlueFissionTech/opus/issues/80)** · `dependency_gated`
  Owner boundary: Tributary; Opus integration. Next: Create one scoped team with two transient specialists and a deterministic delegated task.
  Proof: Non-transitive grants, actor/tenant/project scope, cancellation/retry, tool isolation and cost attribution. Gate: #29,#99,#123; custom teams must not require a new add-on for each agent.

- **[#75 — Scaffold the Repos document-service add-on](https://github.com/BlueFissionTech/opus/issues/75)** · `dependency_gated`
  Owner boundary: Repos; Opus integration. Next: Deliver tenant-scoped small-file create/read/version with an injected store and opaque references.
  Proof: Traversal, checksum, version conflict, partial write, retention, denied share and recovery. Gate: After Tributary per operator;#123 policy; integrate storage/retrieval hooks for Vault without copying engines.

- **[#73 — Scaffold the Vija knowledge-service add-on](https://github.com/BlueFissionTech/opus/issues/73)** · `dependency_gated`
  Owner boundary: Vija; query/storage owners. Next: Build one shared but scoped knowledge index returning cited fixture records.
  Proof: Provenance, conflicting/stale sources, denial, duplicate/orphan handling, no-match and adapter failure. Gate: After Repos; released Linqr/ChainLinq boundary and source governance. Shared knowledge is not shared authority.

- **[#84 — Scaffold the Seiso language-service add-on](https://github.com/BlueFissionTech/opus/issues/84)** · `dependency_gated`
  Owner boundary: Seiso; language owners. Next: Expose deterministic linguistic analysis over one provenance-bearing knowledge fixture.
  Proof: Multilingual/malformed inputs, stable result/version, confidence limits and provider-unavailable fallback. Gate: After Vija per operator; augment Synthetiq/Wise/governors through owned hooks; no mandatory LLM.

- **[#77 — Scaffold the Apropos content-service add-on](https://github.com/BlueFissionTech/opus/issues/77)** · `dependency_gated`
  Owner boundary: Apropos; content workflow owners. Next: Create one cited consultation report draft from approved fixture evidence with human review.
  Proof: Attribution survives revisions, unsupported claims rejected, approval denied safely, no automatic publication. Gate: After Seiso and Repos/Vija; use shared content/report hooks and preserve editorial control.

- **[#76 — Scaffold the Cogito agent-service add-on](https://github.com/BlueFissionTech/opus/issues/76)** · `dependency_gated`
  Owner boundary: Cogito; Opus integration. Next: Compose a value/perspective-driven governor advisory decision with scripted fallback.
  Proof: Perspective boundaries, value constraints, missing evidence, human override, scope and cost evidence. Gate: Correct chatbot-only framing; Cogito strategic principals differ from basic Synthetiq Arkheion coordination. #3/#101 precede activation.

- **[#79 — Scaffold the Aimos goal and KPI add-on](https://github.com/BlueFissionTech/opus/issues/79)** · `dependency_gated`
  Owner boundary: Aimos; Automata goal owner. Next: Augment the existing Wise goal surface with one value-directed goal tree and deterministic observation.
  Proof: Cycles, stale/missing observations, conflicting targets, tenant denial, recalculation and idempotent triggers. Gate: After Cogito per operator; never replace human well-being with a proxy KPI or duplicate Automata goal algorithms.

- **[#71 — Refactor Telaio as an Opus add-on and Arkheion service](https://github.com/BlueFissionTech/opus/issues/71)** · `dependency_gated`
  Owner boundary: Telaio; Opus integration. Next: Map existing product workflows and generate one tenant-scoped graph-to-route plan without deployment.
  Proof: Graph cycles, invalid refs, team/agent nodes, branch conflicts, repeatability, path safety and denied activation. Gate: Audit source before migration;#43,#123 and shared Wise feature discovery; do not transplant Laravel internals.

- **[#74 — Scaffold the Kwikle integration-automation add-on](https://github.com/BlueFissionTech/opus/issues/74)** · `dependency_gated`
  Owner boundary: Kwikle; Synematic; Annex; SimpleClients. Next: Compose one fixture sensor/webhook trigger and action with dry-run and run-status tools.
  Proof: Idempotency, duplicate events, partial failure/compensation, secrets redacted and denied effects. Gate: Immediately after Telaio per operator; external connections remain explicit approved configuration.

- **[#82 — Scaffold the Rithym development environment add-on](https://github.com/BlueFissionTech/opus/issues/82)** · `dependency_gated`
  Owner boundary: Rithym; development-tool owners. Next: Generate one validated model/controller scaffold into a bounded workspace after explicit activation.
  Proof: Traversal, overwrite/recovery, denied code generation, tenant scope, deterministic rerun and cancellation. Gate: After Telaio/Kwikle and Aidea reference MVP; code generation is opt-in, never ambient governor authority.

- **[#83 — Scaffold the Meridia API routing add-on](https://github.com/BlueFissionTech/opus/issues/83)** · `dependency_gated`
  Owner boundary: Meridia; routing/intent owners. Next: Resolve one deterministic route and one intent candidate through existing mapping hooks.
  Proof: Precedence, ambiguity, loops, undeclared targets, tenant denial, stale manifests and no-match. Gate: #13,#110 and stable Synematic/Annex contracts; fuzzy intent remains advisory until authorized routing.

- **[#78 — Scaffold the Versa communication add-on](https://github.com/BlueFissionTech/opus/issues/78)** · `dependency_gated`
  Owner boundary: Versa; transport owners. Next: Deliver one authorized text message and observable receipt through an injected transport.
  Proof: Participant denial, signature failure, duplicate/reordered delivery, timeout and redaction. Gate: Enhance Keryx after#85; transport credentials and unrelated histories never enter governor context.

- **[#72 — Scaffold the Glyphic front-end generation add-on](https://github.com/BlueFissionTech/opus/issues/72)** · `dependency_gated`
  Owner boundary: Glyphic; Vibe/Vibrato; Reactor. Next: Generate and preview one accessible Vibe page inside an approved workspace.
  Proof: Syntax, missing includes/variables, asset policy, traversal/overwrite, rollback manifest and visual evidence. Gate: Use#43,#68 and reviewed presentation contracts; MIX space generation is a later demonstrated capability.

- **[#70 — Scaffold the Lamina model-hosting add-on](https://github.com/BlueFissionTech/opus/issues/70)** · `dependency_gated`
  Owner boundary: Lamina; inference/runtime owners. Next: Expose model catalog, health and a dry-run invocation using one offline model descriptor.
  Proof: Artifact/checksum/capability errors, quota denial, timeout/cancel, hosted/local parity and cost fields. Gate: #126 approved adapter direction; Repos artifact storage and inference routing hooks; no GPU spend/training in this slice.

- **[#69 — Scaffold the Payra payment-service add-on](https://github.com/BlueFissionTech/opus/issues/69)** · `dependency_gated`
  Owner boundary: Payra; payment/provider owners. Next: Record one chargeable task event and fixture payment status without monetary authority.
  Proof: Currency precision, scoped cost rollup, idempotency, duplicate webhook, signature denial and redaction. Gate: Core cost observability begins earlier in#29/#124; paid service activation separately needs provider/legal/recovery review.

- **[#68 — Protect and inject the licensed Arkheion Snow theme](https://github.com/BlueFissionTech/opus/issues/68)** · `planned`
  Owner boundary: Opus public distribution; private deployment owner. Next: Prove public archive exclusion and a synthetic private-theme input contract.
  Proof: No licensed source/output in public archives; missing bundle readiness; synthetic-only adapter tests. Gate: No Snow assets copied; actual private injection requires authorized licensed bundle and deployment context.


### Research

- **[#126 — Research portable inference and agent-runtime adapters](https://github.com/BlueFissionTech/opus/issues/126)** · `decision_gated`
  Owner boundary: Opus; inference package owners. Next: Prepare an ownership and conformance research plan for model/runtime portability.
  Proof: Review taxonomy, drift corpus, cost/privacy bounds, fallback and rollback criteria before research. Gate: Issue explicitly gates provider research and implementation on plan approval; no inference spending or vendor selection here.

- **[#117 — Define an experimental Coeus integration and service promotion gate](https://github.com/BlueFissionTech/opus/issues/117)** · `dependency_gated`
  Owner boundary: Coeus; optional Opus adapter. Next: Stage a read-only experimental evidence envelope with unavailable and failed-gate fixtures.
  Proof: Strong controls, false-positive reporting, reproducibility and bounded task benefit before promotion. Gate: Coeus owns mathematical claims; no decision authority, profiling, ranking override or production generalization claim.


### Interop

- **[#30 — Expose application capability manifests and remote invocation boundaries](https://github.com/BlueFissionTech/opus/issues/30)** · `dependency_gated`
  Owner boundary: Opus; Annex; Synematic. Next: Expose one inert application capability manifest and deny undeclared remote invocation.
  Proof: Schema/version, auth scope, missing capability, timeout/cancel, removal and redacted discovery. Gate: Reviewed Annex trust/provenance contracts; protocol payload never grants authority.

- **[#1 — Serve Mix entry manifest and runtime host surface](https://github.com/BlueFissionTech/opus/issues/1)** · `dependency_gated`
  Owner boundary: Opus host; MIX; Crate; Turntable. Next: Serve a versioned MIX entry manifest and one synthetic compatible presentation route.
  Proof: Manifest validation, session boundary, missing construct/renderer diagnostics and accessible fallback. Gate: Use MIX-owned protocol; Crate/Turntable integration and Navi consumer proof, not a new renderer in Opus.


### Promotion

- **[#105 — Build the Opus marketplace and Annex capability directory](https://github.com/BlueFissionTech/opus/issues/105)** · `dependency_gated`
  Owner boundary: Opus marketplace host; Annex directory owners. Next: Show one reviewed add-on listing and one capability directory entry without installation authority.
  Proof: Signature/hash/provenance, private-entry isolation, compatibility, withdraw/deprecate and denied install. Gate: #43,#56,#104,#107 and Annex contracts; discovery, entitlement and execution grants remain distinct.

- **[#106 — Standardize Arkheion add-on promotion to OCI services](https://github.com/BlueFissionTech/opus/issues/106)** · `dependency_gated`
  Owner boundary: Deployment owner; Opus service host; add-on owner. Next: Build one service promotion evidence packet from an already-proven add-on release.
  Proof: Source/image provenance, health, secrets, persistence, migration, backup/recovery and rollback. Gate: No service deployment before embedded MVP and#104/#107; operation and human approval gates remain explicit.

- **[#103 — Deliver the managed Opus application platform](https://github.com/BlueFissionTech/opus/issues/103)** · `dependency_gated`
  Owner boundary: Managed platform owner; Opus runtime. Next: Provision one isolated synthetic application through an idempotent dry-run control-plane workflow.
  Proof: Tenant boundary, repeat provision, cancellation/recovery, portable export and no ambient provider access. Gate: After reference application proof;#104,#122,#123,#124 and service/operations readiness, not a parallel SaaS-first effort.

## Coverage gaps beyond the current issue list

This queue covers open Opus issues, not every product in the ecosystem. The
following operator requirements need dedicated package/host acceptance cards
before being reported as scheduled or implemented: Pelorus psychograph
integration; Orchestrate; Vault/team data-room retention; Axis; Vista; Aidea;
Jenie's current avatar expression add-on and later rigged persona; Crate/Turntable
presentation; Navi and the standalone MIX daemon; task-cost accounting; distributed
inference and the approved Pokee evaluation path; Morpro contractual acceptance;
Hestia; and the separate Myriam/Lucas/Gaia/Alpha platform boundaries.

Use existing owning repositories/issues where available, with Opus tickets only
for neutral host integration gaps. Package-native PRDs must state scope, affected
people, commands/hooks, permissions, prerequisites, deployable MVP, proof, rollback
and explicit exclusions. Do not invent issue identifiers or duplicate product
logic in core merely to make the board look complete.

## Promotion receipt for each sprint

Record source and dependency revisions; reproducible commands and results;
failed/unattempted checks; migrations and recovery; role/tenant authorization;
provider availability and actual cost; accessibility/UX evidence; human approval;
and the deployment URL/revision when deployment actually happens. Missing evidence
blocks only the corresponding claim, and remains visible for the next sprint.
Reconcile the JSON issue set with GitHub on each refresh and retain unchanged
source titles as identifiers even when a later product correction is recorded.
