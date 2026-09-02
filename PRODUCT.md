# Opus Product Specification

## Document Status

This document describes the Opus core platform at the `main` branch baseline
that includes the product roadmap and Wise VCS release integration. It records
what the product does today, what exists only as a partial implementation, and
what remains an accepted or exploratory direction.

The domain behavior of individual add-ons is outside this specification. Opus
does own the contracts that discover, install, isolate, administer, and expose
those add-ons.

## Product Definition

Opus is an extensible application platform built on BlueCore. It combines a
web runtime, administration surface, Vibe presentation, add-on lifecycle,
Wise command environment, automation-agent composition, and integration
contracts in one application host.

Opus has three delivery forms:

1. the reusable application platform in this repository;
2. a future managed service for creating and operating isolated applications;
3. a future reproducible self-hosted distribution.

A separate Opus deployment is planned to operate the add-on marketplace and
Annex capability directory. These are ecosystem services, not ambient powers
inside every Opus installation.

## Product And Ownership Classes

Every roadmap capability belongs to one of four classes. The class determines
its owner, release evidence, and whether it ships with Opus core.

| Class | Definition | Example boundary |
| --- | --- | --- |
| Native Opus capability | Application composition, policy, lifecycle, administration, presentation integration, readiness, or client behavior shipped by core | Intake state, add-on lifecycle UI, Wise host adapter, provider setup UX |
| Official package-owned add-on | A separately versioned domain capability installed into Opus | Add-on behavior is excluded here; Opus owns only its host contract |
| Opus service host | A separate deployment that composes reviewed Opus and add-on releases with service-owned configuration and operations | Arkheion service promotion under #106 |
| Community or ecosystem service | A separate Opus application supporting the ecosystem rather than every local runtime | Marketplace and Annex directory under #105 |

The managed Opus product is also a separate application and control plane. Its
hosted operating policy must not become a requirement of the reusable core.

## Product Promise

An operator can assemble an application from a stable core and reviewed
add-ons, expose the same authorized operations through web and Wise command
surfaces, and add bounded automation or inference without giving providers or
specialist agents unrestricted application access.

Current evidence supports controlled development and internal application
use. It does not yet support a claim that the managed service, public
self-hosted distribution, marketplace, unattended upgrades, or unattended
production operation are complete.

## Status Language

| Status | Meaning |
| --- | --- |
| Available | Implemented, validated, and reachable through a documented user, developer, or operator surface. |
| Partial | Meaningful implementation exists, but administration, integration, operational proof, or end-to-end usability is incomplete. |
| Planned | An accepted issue or roadmap contract exists, but the capability is not yet usable as a product feature. |
| Exploratory | The capability is implied or under discussion and is not yet an accepted delivery commitment. |
| External | The behavior belongs to an upstream package or optional service; Opus owns only its host integration. |

Documentation and tests are evidence, but do not by themselves move a feature
to Available. A feature also needs an intentional access surface and its
applicable security and lifecycle controls.

## Product Actors

### Visitor

Uses public application routes and presentation selected by the application.
The default theme is a minimal example, not a complete publishing product.

### Application User

Authenticates to an application and may use application or add-on capabilities
granted to their role, tenant, and profile.

### Administrator

Manages local users, content, add-ons, and the current terminal panel. The
existing administration surface is functional in parts but remains incomplete
as a comprehensive control plane.

### Developer Or Integrator

Builds applications and add-ons using mappings, DevElation extension points,
Vibe templates, BlueCore services, Composer entrypoints, and the add-on
generator and validator.

### Central Automation Agent

Coordinates application work through the root agent capability map. It has no
implicit access to specialist tools, private user profiles, provider secrets,
or another tenant.

### Specialist Automation Agent

Represents an installed add-on through an explicit or generated descriptor.
It receives only its own resolved tool map unless reciprocal, non-transitive
grants authorize a narrower exchange.

### Platform Operator

Operates a managed or self-hosted installation, including deployment,
availability, backups, updates, security response, and provider configuration.
Most of this operating surface is still Planned.

## Core Architecture

### Application Runtime

BlueCore owns the reusable framework behavior. Opus supplies application
registration, mappings, gateways, controllers, package resources, runtime-root
resolution, and composed services. Composer-installed and source-checkout
roots are kept distinct so packaged themes, host overrides, and generated
artifacts resolve predictably.

Status: Available for the tested PHP 8.2 application profile; broader public
distribution evidence remains Planned in issue #104.

### Extension Surface

DevElation filters transform documented values and actions observe documented
lifecycle moments. Dependency injection, behaviors, and package-owned
interfaces remain stronger boundaries where they already own the contract.
Extension handlers cannot weaken tenant, role, capability, approval, privacy,
or lifecycle rules.

Status: Partial. Several intake, conversation, profile, and runtime hooks are
implemented. The complete versioned catalog and priority-boundary inventory
remain tracked by issue #110 and PR #111.

### Add-On Lifecycle

Opus and BlueCore discover package definitions, expose install and activation
operations, normalize readiness results, load declarative mappings, and keep
agent contributions lifecycle-aware. The package generator and validator can
produce and inspect the canonical structure from a Composer binary.

Status: Partial. Local CLI and admin surfaces exist, and validation is broad.
Exactly-once activation and full real-database lifecycle acceptance remain in
issues #12, #40, and #43. Marketplace discovery is not part of the local
lifecycle and remains Planned in #105.

### Presentation And Content

Vibe themes provide application and admin templates, includes, sections,
escaped values, explicit trusted markup, and navigation composition. The
administration UI exposes basic user, add-on, content, media, and terminal
panels.

Status: Partial. Rendering and context safety are Available, but content and
media administration are basic, the dashboard contains demonstration data,
and the frontend still carries legacy modules pending the work in #2 and #10.

### Wise Command Environment

Wise is the package-owned command processor. Opus provides one host adapter for
CLI, conversational, programmatic, and future web-console callers. Structured
results preserve output, diagnostics, exit status, confirmation state, and
continuation identity without executing returned content a second time.

Status: Partial. The headless host and CLI path are implemented and tested.
The browser terminal has a panel and optional WebSocket transport, but it is
not yet a production-grade, fully governed TTY. Broader console-manager parity
remains in #13.

### Generation And Scaffolding

Vibrato owns Vibe parsing and rendering semantics. Opus provides bounded
generation operations, safe output paths, add-on scaffolding, and structured
results. Vibe templates are the intended deterministic source for additional
application scaffolds.

Status: Partial. Rendering, validation, bounded file output, and add-on
scaffolding exist. The general code-generation command is not currently
registered in the default console mapping, and application-level generators do
not yet have a complete catalog, administration surface, approval workflow, or
rollback model.

### Automation-Agent Composition

The root application and add-ons declare versioned agent capability maps.
Opus validates descriptors, resolves tenant- and lifecycle-scoped tool maps,
constructs provider-neutral runtimes through factories, persists runtime
state, fences concurrent execution, and delegates selected specialists through
Automata-owned orchestration.

Status: Partial. Capability isolation, lifecycle primitives, cancellation, and
bounded delegation are implemented and tested. Provider/profile adapters,
peer collaboration, operator administration, production recovery, and complete
teardown acceptance remain in #29.

### Conversation And Private Profiles

Synthetiq owns conversational semantics and Wise owns profile resources. Opus
ships deterministic command and profile intent seeds in shadow mode, composes
application/tenant/principal policy, and isolates todos, notes, calendars,
goals, and steps for users and agents.

Status: Partial. Catalog, policy, and isolation contracts exist. Persistent
administration, function-resource support, approved learning promotion,
provider-backed conversation, and full concurrent lifecycle proof remain in
#99.

### Installation And Intake

Runtime bootstrap, database initialization, Composer roots, and an application
intake state model exist. Intake is resumable, requires only the project name,
preserves explicit/default/skipped answers, and does not trigger work by
itself.

Status: Partial. The persisted state machine is implemented. The web and CLI
conversation, Vibe/JenSS intake prompt, provider choice, credential setup,
blueprint review, execution button/command, and discovery sequence remain in
#95.

### Identity, Tenancy, And Authorization

Opus has authentication gateways, user and credential models, basic user APIs,
admin-only routes, agent tool-map isolation, and tenant/principal policy
objects. Presence and Hoom may supply reusable identity behavior, but Opus owns
the host composition and application-facing controls.

Status: Partial. Basic authentication and administration exist. Complete role
and permission management, tenant/application administration, sessions,
recovery, secret visibility, audit, and policy explanation are not yet one
coherent operator surface.

### History, Explainability, And Approval

Structured command diagnostics, correlation context, agent runtime state,
intake revisions, readiness results, and package-level traces provide pieces of
an inspectable history.

Status: Partial. There is no unified operator timeline that explains who did
what, under which grant, with which evidence, provider, approval, result,
rollback, and retained artifact. Shadow mode and approval requirements exist as
policy, but not as a complete administration workflow.

### Operations And Distribution

The repository includes dependency-source audits, platform checks, runtime
contract proof, optional-service behavior, and local Compose tracking.

Status: Partial for development operations. Managed provisioning (#103), a
reproducible self-hosted release (#104), marketplace/directory operations
(#105), service promotion (#106), and immutable Materia conformance evidence
(#107) are Planned. A resettable demonstration profile is also Planned under
#104/#107: it must isolate synthetic tenants and roles, reject production
configuration, disable external side effects, and reset idempotently from a
verified fixture checksum.

## Provider And Model Contract

Inference is optional and provider-neutral at the Opus boundary. A provider may
be hosted, self-hosted, local, or a deterministic fallback. Provider selection
must not broaden an agent's command grants.

The current repository still contains an OpenAI-compatible configuration key
and connector binding, but installation does not collect or validate a
provider, endpoint, model, API key, cost policy, retention policy, or fallback.
That is a product gap, not permission to infer credentials from the host.

A complete setup flow must:

- allow “not now” and a provider-free deterministic mode;
- list provider capabilities without presenting one vendor as required;
- support hosted credentials, self-hosted endpoints, and local models;
- store secrets by reference and never in intake answers or capability maps;
- test connectivity without starting training or executing application work;
- show model, cost, data-use, retention, and availability implications;
- scope provider profiles by application, tenant, agent, and permitted users;
- support ordered routing, fallback, budgets, timeouts, and circuit breaking;
- permit an optional Blue Fission inference service as a default suggestion,
  governed by the same portable provider interface;
- expose setup and later changes through both administration and authorized
  Wise commands with equivalent policy.

Status: Planned as part of the onboarding, profile, and agent work in #95,
#99, and #29. A Blue Fission inference service is Exploratory until it has an
owning service contract, privacy policy, operating evidence, and release plan.

## WordPress-Class Platform Expectations

Opus is not a WordPress clone, but users of an extensible application platform
reasonably expect comparable product concerns. Opus should either provide each
concern, explicitly delegate it to an add-on, or document that it is out of
scope.

| Concern | Current status | Opus position |
| --- | --- | --- |
| Guided installation | Partial | Bootstrap and intake state exist; complete web/CLI guidance and provider setup do not. |
| Themes and overrides | Partial | Vibe rendering and host/package isolation exist; theme discovery, preview, update, and accessibility administration do not. |
| Add-on installation and activation | Partial | Local lifecycle surfaces exist; marketplace, signing, compatibility review, update, and rollback remain. |
| Pages and publishing | Partial | Basic content CRUD and presentation exist; revisions, workflow, scheduling, taxonomy, preview, and recovery remain. |
| Media library | Partial | A panel and modal exist; storage, metadata, transformation, policy, and complete CRUD evidence are incomplete. |
| Users and roles | Partial | Authentication and basic user management exist; comprehensive RBAC/ABAC, tenancy, sessions, and self-service controls remain. |
| Settings | Partial | Configuration files and environment values exist; there is no unified validated settings control plane. |
| Updates | Planned | Dependency audits exist; signed core/add-on/theme update, staging, rollback, and maintenance mode do not. |
| Health and diagnostics | Partial | Runtime/readiness contracts exist; operator dashboards, alerts, repair actions, and support bundles remain. |
| Backup, restore, export, deletion | Planned | Required by #103 and #104; no complete user-facing workflow exists. |
| Resettable demonstration mode | Planned | Synthetic fixtures and fail-closed adapters require a conformance schema in #107 and executable distribution proof in #104. |
| Search and navigation | Partial | Routes, menus, and some Wise search resources exist; application content search and menu administration are incomplete. |
| Import/export and portability | Planned | Managed/self-hosted portability is accepted, but not delivered end to end. |
| Localization and accessibility | Exploratory | Expected release concerns, not yet a complete tracked product surface. |
| Privacy, consent, and retention | Partial | Deny and capture defaults exist for conversation/profile data; unified controls and evidence are incomplete. |
| Scheduled work and queues | Partial | Wise resources and platform primitives exist; a governed job administration surface is not complete. |
| APIs, webhooks, and interoperability | Partial | HTTP mappings and Synematic/Annex integration contracts exist; operator-managed manifests and remote grants remain. |

## External Service Topology

External services must remain separable from the reusable core:

- **Managed Opus:** application provisioning and operations, tracked by #103.
- **Marketplace:** reviewed add-on publication, entitlement, update, and trust,
  tracked by #105.
- **Annex directory:** compatible script, workflow, manifest, and remote
  capability discovery, also tracked by #105 but governed by Annex semantics.
- **Inference services:** optional provider implementations selected through
  portable profiles; no provider is required by the core.
- **Arkheion services:** independent Opus hosts composed around reviewed
  add-ons; their domain behavior is outside this core specification.

Discovery, entitlement, installation, authorization, delegation, and
execution are separate decisions. A successful lookup never grants runtime
authority.

## Product Boundaries

Opus does not own:

- add-on-specific domain behavior;
- inference algorithms or provider transport semantics;
- Wise grammar and command processing internals;
- Automata orchestration algorithms;
- Synthetiq conversation semantics;
- Vibe, Vibrato, JenSS, or Jenerator language semantics;
- Annex protocol semantics or Synematic packet semantics;
- hosted infrastructure policy inside reusable packages.

Opus does own the application-level composition, configuration, lifecycle,
authorization, administration, observability, and evidence that make those
capabilities usable together.

Hestia and The Mix remain candidate applications or add-ons until ownership
and public/private boundaries are defined. Cogito retains package ownership of
decision and guidance semantics, with conversation as an optional adapter
rather than the core integration model. Coeus is limited to the exploratory,
read-only evidence lane in
[#117](https://github.com/BlueFissionTech/opus/issues/117). None becomes a
current Opus capability through implication alone.

## Product Evidence Rules

- Available claims require a documented surface and reproducible validation.
- Partial implementations must identify the missing user, operator, security,
  lifecycle, or operational path.
- Planned work must link to an accepted issue.
- Exploratory work must not be marketed as scheduled or supported.
- Unknown evidence remains unknown.
- Add-on success does not prove core readiness, and core success does not prove
  an add-on or hosted service is ready.
- Pelorus planning context may inform priority and review requirements but does
  not authorize execution, spending, deployment, or release.

## Related Documents

- [Product requirements](PRD.md)
- [Roadmap and capability ledger](ROADMAP.md)
- [Technical specification](SPEC.md)
- [Add-on authoring guide](ADDONS.md)
- [Terminology policy](docs/terminology.md)
