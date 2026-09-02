# Opus Product Roadmap

This roadmap defines the product lines, release sequence, ownership boundaries,
and evidence required to operate and publish Opus. GitHub issue
[#102](https://github.com/BlueFissionTech/opus/issues/102) is the program
tracker. The evidence-based product definition and capability review are
tracked in [#113](https://github.com/BlueFissionTech/opus/issues/113).

Roadmap entries describe intent and gates, not a claim that planned capabilities
are already production-ready. Every release declaration must point to current,
reproducible evidence.

## Vision

Opus is the Blue Fission application platform for building and operating
integrated, automated, agent-enabled software.

It has five connected product responsibilities:

1. **Managed platform:** a web-accessible service for creating and operating
   isolated Opus applications.
2. **Self-hosted distribution:** a downloadable open-source product released
   after its required upstream libraries have supported public versions.
3. **Marketplace and directory:** a dedicated Opus installation for reviewed
   add-ons and Annex-compatible scripts, workflows, manifests, and remote
   capabilities.
4. **Arkheion add-ons and services:** reusable add-ons that can also be composed
   into independently deployed enterprise services.
5. **Materia integration proof:** production-shaped evidence that the Blue
   Fission libraries form a coherent application stack.

The roadmap also distinguishes four ownership classes: native Opus
capabilities, official package-owned add-ons, separate Opus service hosts, and
separate community or ecosystem services. The marketplace/directory and the
managed control plane are separate applications built with Opus; they are not
framework-core behavior.

## Product Topology

| Product surface | Responsibility | Deployment boundary | Tracker |
| --- | --- | --- | --- |
| Opus runtime | Application composition, lifecycle, policy, admin, Wise command hosting, and agent integration | Reusable application package | [#102](https://github.com/BlueFissionTech/opus/issues/102) |
| Frontend presentation | Reproducible browser assets, Reactor migration, Vibe integration, accessibility, and responsive behavior | Public application bundle | [#2](https://github.com/BlueFissionTech/opus/issues/2), [#10](https://github.com/BlueFissionTech/opus/issues/10) |
| Managed Opus | Provisioning, tenancy, operations, metering, recovery, and managed application lifecycle | Hosted control plane plus isolated application runtimes | [#103](https://github.com/BlueFissionTech/opus/issues/103) |
| Self-hosted Opus | Reproducible public installation, upgrade, rollback, documentation, and support policy | Downloadable source and distribution artifacts | [#104](https://github.com/BlueFissionTech/opus/issues/104) |
| Marketplace and Annex directory | Publisher, artifact, release, compatibility, trust, discovery, entitlement, and directory workflows | Dedicated Opus installation | [#105](https://github.com/BlueFissionTech/opus/issues/105) |
| Arkheion add-ons | Reusable domain capabilities with lifecycle, command, agent, and interoperability contracts | Installable add-on packages | [#67](https://github.com/BlueFissionTech/opus/issues/67) |
| Arkheion services | Enterprise service composition and operations for one reviewed add-on capability | One dedicated Opus installation per service | [#106](https://github.com/BlueFissionTech/opus/issues/106) |
| Materia proof | Versioned integration, compatibility, failure, security, and operational evidence | Release-specific conformance snapshot | [#107](https://github.com/BlueFissionTech/opus/issues/107) |

## Capability Status

This ledger is the current planning baseline for Opus core. It excludes the
domain behavior of individual add-ons. `Available` requires an intentional,
documented access surface as well as implementation evidence. `Partial` means
that useful code exists but a product, administration, security, lifecycle, or
operational path remains incomplete. `Planned` has an accepted tracker.
`Exploratory` is an implication or discussion, not a delivery commitment.

The tables below are a readable summary. The machine-readable conformance
record delivered through #107 must carry the following fields for every
capability so that a label cannot substitute for evidence:

| Field | Required content |
| --- | --- |
| State | Available, Partial, Planned, or Exploratory under the definitions above |
| Ownership | Native Opus, package-owned add-on, service host, or ecosystem service |
| Evidence | Current code, test, documentation, installation, and operating proof |
| Dependencies | Required upstream releases, services, and unresolved contracts |
| Launch gate | The exact condition that permits promotion to the next state |
| Success measure | A reproducible user or operator outcome |
| Risk | Security, compatibility, reliability, cost, privacy, or support exposure |
| Commitment | Accepted delivery commitment or non-binding product aspiration |

### Runtime And Extensibility

| Capability | Status | Current evidence | Next gate |
| --- | --- | --- | --- |
| PHP application runtime and mappings | Available | BlueCore registration, HTTP routes, gateways, package bootstrap, and PHP 8.2 test baseline | Expand the supported runtime matrix under #104. |
| Composer-installed host/package root isolation | Available | Runtime-root resolver, Composer binary entrypoint, package-theme tests; #50 closed | Preserve source/dist parity in every release. |
| DevElation hooks and filters | Partial | Intake, conversation, profile, and command-runtime extension points exist | Complete the catalog and priority-boundary proof in #110 and PR #111. |
| Runtime contract proof | Available | `contract proof`, `contract targets`, and `contract validate` commands with fixtures | Bind proof to immutable releases in #107. |
| Dependency provenance and VCS audit | Available for development | Root-only registry, source transport, lock, and distribution checks | Deliver the credential-free public baseline in #56 and #104. |
| Local Compose development profile | Partial | Compose assets and harness integration exist | Complete clean `/health` startup evidence in #20. |

### Installation And Application Setup

| Capability | Status | Current evidence | Next gate |
| --- | --- | --- | --- |
| CLI/runtime bootstrap | Available | Shared runtime bootstrap and terminal/add-on entrypoint tests | Include it in packaged install and upgrade proof. |
| Ordered database initialization | Available | Idempotent initialization work completed in #25 | Repeat on every supported database target. |
| Resumable application intake state | Available as a service | Persisted sessions, revision guard, defaults, and skip/pause/resume/complete transitions | Expose it through web, CLI, and conversation in #95. |
| Guided web and CLI installation | Partial | Installation commands and intake domain exist but are not one guided flow | Complete the installer experience in #95 and release proof in #104. |
| Vibe/JenSS intake blueprint | Planned | Accepted architecture in #95 | Author, version, validate, and persist the first production prompt. |
| Provider selection and credential setup | Planned | Legacy OpenAI-compatible config exists, but no provider-neutral setup | Add optional hosted/local setup, secret references, policy, and connectivity proof through #95/#99/#29. |
| Shadow-mode build request | Partial | Conversation defaults require shadow/review mode | Add an explicit button and Wise command, plan preview, approval, and persisted outcome in #95. |

### Administration And Publishing

| Capability | Status | Current evidence | Next gate |
| --- | --- | --- | --- |
| Administration shell and navigation | Partial | Authenticated Vibe shell and panels for dashboard, users, add-ons, content, media, and terminal | Replace demonstration data and connect complete governed services. |
| Authentication and credentials | Partial | Login/logout, gateways, credential models, and password operations | Add session, recovery, policy, audit, and managed identity administration. |
| Users | Partial | Basic admin listing, lookup, save, and credential status APIs | Add complete role, permission, tenant, lifecycle, invitation, and self-service controls. |
| Roles, permissions, and explicit denies | Partial | Gateways plus agent/profile policy objects and tests | Provide one application/tenant/user administration surface with policy explanation. |
| Tenant and application administration | Planned | Scope contracts exist in intake, profiles, and agents | Implement lifecycle and control-plane requirements in #103. |
| Settings | Partial | Layered config and environment contracts exist | Add validated application, tenant, principal, provider, and secret-reference UI and commands. |
| Pages/content | Partial | Basic content CRUD, templates, slug, URI, publish flag, and admin panel | Add revisions, preview, workflow, scheduling, taxonomy, deletion/recovery, and policy. |
| Media | Partial | Admin panel and create modal exist | Complete storage, upload, metadata, policy, transformations, deletion, and API evidence. |
| Navigation | Partial | Vibe menu composition and permission-aware menu objects exist | Add menu administration, stable destinations, and content/add-on contribution workflow. |
| Themes | Partial | Vibe rendering, includes, sections, escaping, trusted markup, and host overrides exist | Add discovery, preview, activation, update, compatibility, accessibility, and rollback. |
| Search, localization, comments, and taxonomy | Exploratory | Adjacent primitives exist but no complete Opus product contract | Decide core versus add-on ownership and open bounded issues. |

### Commands, Generation, And Automation

| Capability | Status | Current evidence | Next gate |
| --- | --- | --- | --- |
| Wise headless command host | Available | One structured host for CLI/programmatic execution, confirmation, diagnostics, and exit status; #9 closed | Keep channel behavior and release compatibility tested. |
| Interactive CLI | Available | `cmd i` loop with structured execution and pinned confirmations | Add discoverable setup/help and operator documentation. |
| Browser terminal/TTY | Partial | Admin panel, xterm assets, and optional Ratchet transport exist | Select the production transport and add authentication, reconnect, resize, cancellation, isolation, and load proof under #13. |
| UI/CLI command parity | Partial | Shared service boundaries exist for some operations | Publish a parity matrix and route every supported control through one owning operation. |
| Vibe rendering and bounded file generation | Available | Syntax validation, deterministic rendering, safe paths, and structured results | Add reviewed catalogs and approval/rollback workflows. |
| General code scaffolding | Partial | `CodeManager` and Vibe generation service exist; the default code command remains unregistered | Define scaffold types, preview/diff, authorization, validation, and rollback under #13/#95. |
| Canonical add-on generator and validator | Partial | Composer binary, structural validation, templates, and standalone/embedded tests exist | Finish lifecycle and compatibility acceptance in #43. |
| Scheduled work, queues, todos, notes, calendars, goals, and steps | Partial | Wise resources and scoped profile maps exist | Add persistent profile adapters, user/tenant administration, policy, and operational views in #99. |

### Conversation, Inference, And Agents

| Capability | Status | Current evidence | Next gate |
| --- | --- | --- | --- |
| BotMan web and CLI conversation adapters | Partial | Chat route, widget, CLI driver, and middleware exist | Bind complete scoped conversation outcomes, identity, provider selection, and error presentation. |
| Deterministic conversation and command seeds | Available as configuration | Versioned intents, safe fallbacks, and shadow/review defaults exist | Add idempotent persistence and administration in #99. |
| Scoped private Wise profiles | Partial | Application/tenant/principal policy and profile isolation are tested | Complete the function adapter, persistent resources, delegation administration, and concurrency proof in #99. |
| Central agent capability map | Available | Versioned root map, validation, filtered discovery/invocation, and deny-by-default resolution; #66 closed | Add operator configuration and complete runtime/provider acceptance. |
| Add-on specialist capability maps | Available as a host contract | Declared/generated/central/disabled modes and leakage tests exist | Complete add-on lifecycle integration and administration. |
| Provider-neutral runtime composition | Partial | Descriptors, factories, lifecycle, execution fences, cancellation, and state stores exist | Add provider/profile adapters, configuration, teardown, and production recovery in #29. |
| Central-to-specialist delegation | Partial | Bounded Automata delegation and normalized outcomes exist | Add explicit peer collaboration, operator visibility, approval, and production proof in #29. |
| Multiple providers and self-hosted models | Planned | Runtime interfaces permit opaque provider profiles | Implement settings, routing, fallback, budget, privacy, and health contracts. |
| Optional Blue Fission inference service | Exploratory | Product need identified; no owning service or release evidence exists | Define provider contract, operating policy, privacy, cost, availability, and fallback before commitment. |
| Machine-learning observation and promotion | Partial | Shadow defaults, privacy exclusions, scoped cache identity, and review rules exist | Add reviewed persistence, evaluation, approval, activation, rollback, and audit in #99. |

### Governance, Operations, And Ecosystem

| Capability | Status | Current evidence | Next gate |
| --- | --- | --- | --- |
| Structured outcomes and diagnostics | Available in key command/lifecycle paths | Command presentations, readiness normalization, reason codes, and correlation context exist | Make coverage consistent across every core operation. |
| Unified activity history and explainability | Partial | Intake revisions, command diagnostics, agent state, and package traces are separate | Build one redacted operator timeline with evidence, grant, approval, retry, cancellation, and rollback context. |
| Approval and shadow execution | Partial | Policy contracts require review and pin continuations | Add queue, review, approval/rejection, scheduling, execution, and revocation administration. |
| Health, readiness, logs, metrics, and support evidence | Partial | Runtime and lifecycle readiness primitives exist | Complete operator dashboards, alerts, repair actions, retention, and support bundles. |
| Backup, restore, export, and deletion | Planned | Accepted managed/self-hosted requirements | Implement and prove in #103 and #104. |
| Core/add-on/theme updates and rollback | Planned | Dependency and package audits exist | Add signed update channels, compatibility plans, maintenance mode, backup, health check, and rollback in #104/#105. |
| Resettable demonstration host | Planned | The isolation and side-effect contract is accepted; executable proof does not exist | Define versioned synthetic fixtures in #107 and prove isolated, checksum-resettable, production-rejecting operation in #104. |
| Managed Opus service | Planned | Product boundary accepted | Deliver isolated provisioning and operating evidence in #103. |
| Self-hosted public distribution | Planned | Product boundary accepted | Deliver reproducible public artifacts and lifecycle evidence in #104. |
| Add-on marketplace | Planned | Discovery, trust, entitlement, and install separation accepted | Deliver the dedicated application in #105. |
| Annex capability directory | Planned | Directory boundary and authorization separation accepted | Deliver versioned discovery and conformance through #105 and Annex-owned contracts. |
| Materia conformance publication | Planned | Runtime and dependency proofs provide starting evidence | Publish an immutable release matrix in #107. |
| Arkheion service promotion | Planned external composition | Shared host/add-on boundary is documented | Prove the promotion contract in #106 without moving add-on domains into core. |
| Accessibility, localization, telemetry, and support policy | Exploratory | Required release concerns are recognized | Assign owners, baselines, consent rules, and tracked delivery issues. |

The detailed product boundary is in [PRODUCT.md](PRODUCT.md), and functional
requirements and release gates are in [PRD.md](PRD.md).

## Architecture Invariants

- Opus owns application composition and policy. Direct upstream libraries retain
  their package-owned semantics.
- Managed-hosting policy must not leak into the reusable self-hosted package.
- Add-on domain behavior stays in the add-on. A service host composes a reviewed
  release and does not fork that behavior.
- Marketplace discovery, package entitlement, installation approval, runtime
  authorization, and agent capability grants are separate decisions.
- Annex directory metadata can describe a compatible capability but cannot
  authorize or execute it.
- Central and specialist agents are provider-neutral and deny-by-default.
- The central agent does not inherit a specialist tool surface when the add-on
  has its own agent. Add-ons without a declared specialist may request a bounded
  generated specialist during installation.
- Specialist agents, peer agents, human users, applications, and tenants do not
  inherit one another's private Wise profiles or command surfaces.
- Wise is the shared headless command environment. Automata owns orchestration,
  Synthetiq owns conversational behavior, and Opus owns host composition and
  access policy.
- Vibe and Vibrato provide template and generation boundaries; JenSS and
  Jenerator provide configurable script behavior; Annex and Synematic provide
  interoperability boundaries.
- Optional capabilities remain optional on clean installations and return
  explicit unavailable states.
- Add-on integration uses stable, documented DevElation hooks and filters at
  intentional application boundaries. Filters cannot bypass tenant, identity,
  authorization, privacy, or lifecycle invariants, and stronger interfaces and
  dependency injection remain authoritative where they already own a contract.
- Public artifacts contain no secrets, customer data, private prompts, local
  source paths, internal-only configuration, or separately licensed assets.

## Release Sequence

The tracks can advance in parallel, but a later promotion cannot bypass an
earlier dependency or evidence gate.

The reviewed delivery order is:

1. Land the extension-point catalog in #110/PR #111 and correct the Kapsle
   conformance defect in #109.
2. Publish the ownership and conformance taxonomy in #107, then prove the
   reproducible self-hosted distribution in #104.
3. Validate Kapsle and Hoom as the first two official add-on contracts.
4. Prove the lean service-host template in #106.
5. Deliver the minimum marketplace and Annex directory service in #105.
6. Complete resumable onboarding, private profiles, and side-effect-free
   consulting guidance in #95, #99, and #101.
7. Advance further add-on and service waves according to evidence and product
   value, and deliver the managed platform in #103 only after the preceding
   gates are reproducible.

This ordering is a planning dependency graph. It does not authorize release,
deployment, spending, or production promotion.

### Phase 0: Foundation and Repeatability

- Resolve all required dependencies from reviewed public releases.
- Prove clean installation, deterministic initialization, upgrade, recovery,
  and representative add-on lifecycle behavior.
- Stabilize the Wise command host, central and specialist agent composition,
  scoped profiles, authorization, audit, and shadow-mode execution.
- Establish one reproducible frontend asset source map, migrate shared bindings
  through Reactor-owned exports, and enforce production bundle validation.
- Complete resumable application onboarding and application blueprint
  persistence.
- Establish health, readiness, diagnostics, logging, security, and conformance
  evidence formats.
- Keep test fixtures provider-neutral, offline by default, and free of protected
  data.

### Phase 1: Conformance And Self-Hosted Release Candidate

- Freeze a supported upstream compatibility matrix.
- Complete the versioned extension catalog in #110 and resolve release-blocking
  package conformance defects.
- Publish the ownership and Materia conformance taxonomy in #107.
- Produce reproducible source and distribution archives with source/dist parity.
- Publish checksums, provenance, dependency and license inventories, release
  notes, migration guidance, and security/support policies.
- Verify documented web and CLI installation, upgrade, backup, recovery, and
  representative add-on workflows.
- Verify the production frontend build, public asset provenance, responsive and
  accessibility checks, and completion or explicit migration status for every
  remaining legacy dashboard import.
- Tie the release to an immutable Materia conformance snapshot.
- Exclude all private deployment assets and separately licensed themes.

### Phase 2: Official Add-On And Service-Host Proof

- Validate one official add-on end to end as the extension conformance canary,
  then repeat with a distinct identity-oriented add-on.
- Prove direct Composer installation before marketplace delivery.
- Use the shared #106 promotion contract to build one lean service-host
  template without moving package-owned domain behavior into Opus core.
- Prove API, Wise command, specialist-agent, and Annex manifest compatibility
  only where those surfaces are declared.
- Keep deployment, spending, DNS, and production promotion behind explicit
  human and operating-policy approval.

### Phase 3: Marketplace And Directory Minimum Service

- Operate the marketplace and Annex directory as a separate Opus application.
- Publish one reviewed add-on release with provenance, signature,
  compatibility, license, maturity, and lifecycle evidence.
- Publish one Annex-compatible directory entry with a versioned manifest,
  trust references, freshness, and capability metadata.
- Prove public and organization-private visibility without metadata leakage.
- Prove search, inspection, policy review, install, update, rollback, withdraw,
  and deprecate paths.
- Keep discovery, entitlement, installation approval, runtime grants, and
  execution as separate decisions.

### Phase 4: Guided Application Experience

- Complete resumable onboarding, private profiles, provider-neutral setup, and
  side-effect-free consulting guidance under #95, #99, and #101.
- Exercise the admin surface, Wise command parity, central agent, one
  specialist, and dry-run automation.
- Add explainable history, approval, generation preview/apply/rollback, and
  complete user/tenant administration.
- Advance additional add-on and service work in evidence- and value-based
  waves rather than by catalog breadth.

### Phase 5: Managed Platform Alpha

- Provision and remove an isolated application through an idempotent workflow.
- Establish tenant/application identity, lifecycle, quotas, audit, backup,
  recovery, export, and deletion.
- Define support, incident, metering, billing, cost-control, and service-level
  boundaries.
- Prove self-host parity, tenant isolation, rollback, and bounded operating cost.
- Run production-shaped internal workloads before external availability.

### Phase 6: Production Expansion

- Advance managed platform and service availability by measured readiness rather
  than catalog size.
- Add marketplace commercial policy, publisher operations, review capacity,
  entitlement, and support.
- Add Annex federation and external directory participation with explicit trust
  and freshness policy.
- Improve portability between managed and self-hosted installations.
- Publish compatibility and deprecation windows for Opus, add-ons, services, and
  upstream packages.

## Workstreams

### Managed Platform

Tracked in [#103](https://github.com/BlueFissionTech/opus/issues/103).

The managed product owns provisioning, tenancy, application placement,
operations, exportability, metering, billing, support, and recovery. An
application runtime must remain portable and must not require hosted-only code
to function.

### Self-Hosted Distribution

Tracked in [#104](https://github.com/BlueFissionTech/opus/issues/104).

A public release depends only on supported public package versions. Development
branches may validate unreleased upstream work, but local source pins and private
repository credentials cannot enter a release artifact.

### Frontend Presentation

Tracked in [#2](https://github.com/BlueFissionTech/opus/issues/2) and
[#10](https://github.com/BlueFissionTech/opus/issues/10).

Opus owns reproducible application bundling and presentation integration.
Reactor owns reusable browser bindings and runtime UI behavior, while Vibe and
Vibrato own server-authored template semantics. Frontend release evidence must
identify authored and generated asset ownership, execute a production build,
prevent direct legacy-module regressions, and cover responsive, accessible,
theme-independent application and add-on UI behavior.

### Marketplace and Annex Directory

Tracked in [#105](https://github.com/BlueFissionTech/opus/issues/105).

The marketplace publishes add-on artifacts and commercial/support metadata. The
Annex directory publishes compatibility and discovery metadata for scripts,
workflows, manifests, and remote capabilities. They may share identity, search,
trust, and review infrastructure while retaining separate artifact and
authorization models.

### Arkheion Program

Tracked in [#67](https://github.com/BlueFissionTech/opus/issues/67), with service
promotion in [#106](https://github.com/BlueFissionTech/opus/issues/106).

Each Arkheion capability follows this progression:

1. package-owned contract;
2. canonical Opus add-on;
3. clean embedded-host evidence;
4. reviewed marketplace artifact;
5. dedicated Opus service composition;
6. OCI operational evidence;
7. controlled enterprise availability;
8. broader availability only when maturity, support, and business gates are met.

The separately licensed Snow theme may be supplied to approved Arkheion
deployments, but it is not an Opus default and must never enter public
repositories, packages, fixtures, CI artifacts, or public image layers.

### Materia Conformance

Tracked in [#107](https://github.com/BlueFissionTech/opus/issues/107).

Opus demonstrates upstream value through real application behavior. The
conformance matrix must identify the supported version, owned boundary,
exercised behavior, test evidence, operational evidence, maturity, limitations,
and migration policy for every release-critical dependency. Installing a
package is not sufficient proof.

## Release Evidence

Every managed, self-hosted, marketplace, add-on, or service release candidate
must identify the applicable evidence:

- source commit, package versions, archive or image digest, and provenance;
- compatibility and migration matrix;
- unit, contract, integration, lifecycle, and production-shaped test results;
- security audit, threat boundaries, authorization, tenancy, and redaction
  evidence;
- performance, load, timeout, cancellation, retry, and recovery behavior;
- health, readiness, diagnostics, logs, metrics, traces, and alert coverage;
- backup, restore, rollout, rollback, and disaster-recovery evidence;
- license, asset, secret, and distribution-hygiene checks;
- documentation, support, incident, deprecation, and ownership status;
- the versioned DevElation extension-point catalog, payload compatibility,
  execution order, post-filter invariant tests, and deprecation evidence tracked
  in [#110](https://github.com/BlueFissionTech/opus/issues/110);
- explicit maturity: planned, experimental, evolving, stable, deprecated, or
  unavailable.

Unknown evidence remains unknown. It must not be inferred from adjacent package
or deployment success.

Any release that exposes Opus DevElation hooks or filters is blocked when the
#110 catalog is absent or incomplete, including when any exposed extension
point lacks a validated catalog entry. Releases are also blocked when payload
compatibility or ordering is unknown, or when post-filter invariant and
conformance tests are missing or failing.

## Prioritization

1. Protect production correctness, security, tenant isolation, and
   recoverability.
2. Complete bounded foundations needed by current launch workloads.
3. Prefer end-to-end proof of one representative path over broad incomplete
   catalog coverage.
4. Promote add-ons and services only when their direct prerequisites and
   operational owners are ready.
5. Keep public package contracts general and reusable.
6. Record new discrete needs as issues and attach their evidence to the
   applicable roadmap track.
