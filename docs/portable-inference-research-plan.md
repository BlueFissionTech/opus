# Portable inference: proposed architecture and research plan

Status: **R1–R4 discovery scope approved by the operator on 2026-10-19**.
Package-owner acceptance, adapter contracts and provider selection remain open.
This document records the pre-research gate for [issue #126](https://github.com/BlueFissionTech/opus/issues/126).
Approval of this plan authorizes the bounded discovery work below. It does not
approve a dependency installation, provider credential change, production
activation, generated adapter execution, or a claim of behavioral equivalence.

## Decision to make

Choose an ownership and evaluation model that lets an application propose a
model, provider, runtime or hosting-mode change while preserving explicit
capability, privacy, cost and human-control boundaries. The output is a reviewed
architecture and evidence-backed implementation backlog. It is not an adapter
implementation in Opus core.

Human benefit, understandable operation, accessibility, recourse and sustainable
maintenance are the intended outcomes. Price, speed and benchmark scores are
constraints and evidence; they cannot compensate for a broken authority boundary
or conceal a material reduction in useful behavior.

## Proposed ownership decision

These assignments require acknowledgment from the owning maintainers during
discovery. They do not create new upstream obligations by declaration.

| Boundary | Proposed owner | Required review output |
| --- | --- | --- |
| Transport, authentication headers, endpoint errors, streaming transport | SimpleClients or the existing transport package | Exact released API and supported transport behavior; missing APIs become owner issues |
| Strategy/model execution, routing, budgets, orchestration and task telemetry | Automata | Versioned execution/usage/trace and cancellation contracts; deterministic and generative paths stay distinct |
| Conversation scripts, prompt/message construction, context and fallback language behavior | Synthetiq | Normalization and fidelity limits, including refusal and conversation-state handling |
| Textual commands, tool schemas, discovery, confirmation and continuation | Wise | Shared command contract and permission behavior for UI, CLI and agent callers |
| Host configuration, tenant/actor policy, installation, activation, migration and rollback | Opus | Current-authority checks, lifecycle, approval record, normalized application outcome and operator visibility |
| Provider/model-family/version adaptation | Separately versioned adapters in the natural owning package | Maintainer, supported exact targets, license, conformance evidence, replacement and end-of-support policy |
| Application task meaning and acceptable drift | Application owner with affected operators | Versioned task corpus, rubric, limits and explicit migration disposition |

Do not implement provider SDK calls, tokenization semantics, or model-specific
prompt repairs in the host simply to bypass an upstream release gap. A temporary
compatibility layer must have an owner, exact support range, tests and removal
condition. Do not require inference for installation or deterministic workflows.

## Layered adapter taxonomy

Evaluate composition of five independently versioned layers:

1. Transport binding: hosted endpoint, self-hosted endpoint, local process or
   embedded runtime. Availability of one binding does not imply another.
2. Runtime contract: request/response, streaming events, tools, usage, stop,
   timeout, retry and cancellation behavior.
3. Model family and exact model revision: instruction semantics, context limits,
   tokenizer assumptions, prompt format and supported output modes.
4. Capability transformation: an explicit, tested transformation with a named
   input/output contract and disclosed losses or operational costs.
5. Application profile: task policy, permitted candidates, budgets, fallback,
   data handling and acceptable drift. This profile cannot grant itself access.

Proposed capability dispositions are `native`, `transformed`, `emulated`,
`degraded`, `unsupported`, and `unknown`. Report the disposition per capability
and exact target, with evidence and validity scope. `Unknown` is not support;
emulation is not native implementation. Mandatory capabilities with unknown or
unsupported behavior block promotion. Degradation requires a visible operator
decision and an application-approved alternative.

The design review must settle a versioned descriptive manifest containing:

- Stable package/adapter identity, schema version, immutable source and artifact
  digests, maintainer and license evidence.
- Exact supported runtime/model targets and compatible upstream contract ranges.
- Per-capability disposition, transformation chain, constraints, test evidence,
  evidence date and unresolved behavior.
- Context/token accounting assumptions; message roles; tools and structured
  output; streaming, refusal, stop, timeout, cancellation and retry semantics.
- Privacy/retention and hosting assumptions, resource estimates, metering units,
  provenance, support window and replacement/rollback references.

This is a field inventory for review, not a new competing protocol schema.
Reuse accepted Annex/package manifests where they cover these fields. Authority
records, credentials and grants stay separate from descriptive metadata. A
signed manifest establishes its provenance, not permission to execute it.

## Discovery sequence and concrete deliverables

| Gate | Work | Deliverable and acceptance |
| --- | --- | --- |
| R0: approve this plan | Review ownership, research scope and evidence rules | Explicit maintainer/operator approval recorded on the PR; unresolved decisions remain visible |
| R1: source and documentation review | Read exact released upstream contracts and primary provider/runtime documentation | Responsibility matrix, dated citations, supported-target inventory and a gap log; no provider calls or installs |
| R2: conformance design | Design corpus, normalization, migration and review contracts | Versioned fixture specification, scoring rubric, proposed manifest mapping and negative cases reviewed by owners; no adapter implementation |
| R3: candidate assessment design | Compare candidates against the accepted rubric using documented evidence | Maintained-adapter shortlist with unknowns, licensing/privacy/support requirements and proposed pilot costs; no implied selection or activation |
| R4: architecture disposition | Resolve owner disagreements and split implementation | Accepted decisions plus bounded issues, each naming fixtures, validation commands, release dependencies and a maintainer |

The first research pass uses public primary documentation and existing accessible
source only, with zero provider-call spend and no credential use. Operator-nominated
OpenAI, Gemini, Pokee and Hugging Face integrations may enter the R1 inventory;
their suitability, pricing, hosting options and support remain unverified until
that research occurs. Pokee fallback and any self-hosting path must be evaluated
independently; an API integration does not establish self-hosting rights or a
deployable server. Include deterministic strategies, local small models and
specialized runtimes rather than treating a large hosted model as the default.

Any later live pilot is a separate proposal specifying exact endpoints/models,
synthetic data, credentials mechanism, call/token/currency ceilings, timeout,
retention, reviewer and stop condition. Existing operator authorization must be
checked before requesting additional approval. Do not infer installation or
production authority from permission to compare documentation.

## Application-owned conformance corpus

Each fixture records a stable ID and corpus version, task/capability category,
synthetic input and context, expected outcome or rubric, required assertions,
allowed variability, privacy classification and resource ceiling. Preserve exact
encoding, whitespace and line endings where they are part of the test. Record
whether a seed is supported; never promise deterministic generation from a seed
alone. A failure, skip or unavailable measurement remains explicit.

The initial design must cover:

| Area | Required positive and negative cases |
| --- | --- |
| Instructions and context | Role precedence, conflicting instructions, Unicode, whitespace/line endings, near-limit context, truncation and omitted context |
| Tools and output | Valid/invalid arguments, unknown tools, structured output/schema failure, multiple tool calls, no-call responses and malformed streaming fragments |
| Authority and isolation | Denied capability, other tenant/actor, expired/revoked grant, continuation mismatch, injected stored instructions, missing mandatory proof and escalation denial |
| Lifecycle | Timeout before/after possible effect, cancellation request versus confirmed cancellation, duplicate response, unavailable provider, bounded retry and complete teardown |
| Conversation | Deterministic script/menu success, refusal, early stop, multi-turn continuity, fallback without authority expansion and preserved uncertainty |
| Operations | Usage unavailable versus zero, cache hit, partial stream failure, resource exhaustion, model revision change and rollback with pending work |
| Hosting | Equivalent logical task through hosted, self-hosted and local fixtures, with explicit unsupported features and no network requirement for the base suite |

An application owner must supply at least one useful end-to-end workflow, not
only syntax and protocol examples. Start with a bounded consultation or operator
task using synthetic facts, explicit permissions and a human review point.
Counterbalance it with deterministic work that should never require an LLM.
Include operator comprehension and correction burden in the evaluation rubric.

## Comparison and scoring method

Use paired baseline/candidate runs on the same corpus and policy. Before running
an experiment, record the acceptance thresholds, repetition count, sampling
method, resource ceilings and missing-data rule. Do not tune these after seeing
candidate outcomes. Keep a held-out set separate from adapter development.

Report per-category task success and rubric outcomes, tool/schema reliability,
refusal and stop correctness, context preservation, latency distribution, usage
and cost per successfully completed task, plus operator intervention burden.
Include failures and censored/time-limited runs in the denominator. State model
and adapter revisions, host configuration and sample sizes. For stochastic
outcomes, report paired uncertainty estimates and disagreements, not just a
single average. Human-scored samples need a published rubric and independent
review of disagreements; automated similarity is insufficient by itself.

Authority/isolation violations, silent unknown-to-supported promotion, unsafe
effect replay, secret disclosure and missing rollback proof are hard failures
for the evaluated scope. No weighted overall score may offset them. Passing a
finite corpus is evidence for that corpus and target, not proof of general
safety or lossless model equivalence. A missing cost or quality measurement is
`unknown`, never zero or a passing result. Candidate recommendations must expose
which tasks improve, regress, degrade or remain unmeasured.

## Migration, activation and rollback

The proposed shared UI/CLI/Wise command backbone supports inspection, validation,
comparison, proposal, activation and rollback under the same current host
policy. Agent access is proposal-only until an authorized operator approves an
exact artifact/configuration and scope. A recommendation or favorable score
does not perform activation.

Promotion requires a review packet containing immutable baseline/candidate
identities, corpus/results, explicit unsupported/degraded capabilities, approved
data and cost policy, compatibility constraints, human disposition, rollback
target and effect-reconciliation plan. A model revision, provider substitution,
hosting-mode transition or license/price-driven change invalidates any approval
whose bound assumptions changed.

Shadow evaluation must not duplicate business effects. Use inert fixtures or a
verified side-effect-free execution path; suppressing only the final write is
not enough when tools can already act. Canary activation needs a bounded tenant
or workload, observable stop conditions and an accessible operator override.
On rollback, stop new admission, reconcile in-flight/uncertain outcomes, retain
audit evidence and resume only through the previous compatible configuration.
Do not automatically retry a task whose effects are unknown.

## Custom adapters and security review

Generated or user-authored adapters remain inert source proposals. The reviewed
flow is proposal, static validation, isolated tests with no ambient credentials,
owner review, artifact integrity/approval, scoped installation, explicit
activation, health monitoring and reversible deactivation. No stage may enlarge
network/tool/tenant access or retention merely to make a test pass.

Before committing to a maintained package, record license/distribution rights,
model terms, credential handling, egress and retention requirements, dependency
support, vulnerability response, maintainer capacity, upgrade policy and a
replacement strategy. Keep private prompts, raw provider payloads, secrets and
unapproved customer data out of public fixtures and reports. Use redacted
evidence with enough provenance for authorized reviewers to reproduce the test.

## Required review and follow-up split

The approving operator should decide whether the proposed ownership and R1–R4
scope are acceptable. Owning maintainers then confirm or revise their boundaries;
the application owner approves the corpus and drift thresholds before evaluation.
Unresolved disagreements block the affected implementation, not unrelated
deterministic work.

After R4, create separate implementation issues for manifest/capability
negotiation, normalization adapters, fixture runners, migration reporting,
shared administration commands, controlled activation/rollback and each selected
maintained adapter. Each must name an exact owner, accepted upstream contracts,
positive/negative fixtures, executable validation commands and a release gate.
Do not create empty adapter packages as a substitute for that evidence.

This plan does not complete #126's discovery acceptance criteria. The operator
approved discovery; owner decisions, reviewed corpus and migration design,
evidence-backed candidate selection and implementation issue split remain open.
See the [initial research findings](portable-inference-research-findings.md) for
the dated source review and documented candidate limitations.
