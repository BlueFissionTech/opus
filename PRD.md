# Opus Product Requirements Document

## Summary

Opus is an extensible application platform for building, operating, and
governing web applications, automations, inference-backed experiences, and
integrations. It combines BlueCore application behavior with Vibe
presentation, Wise commands, Automata orchestration, Synthetiq conversation,
DevElation extension points, and package-owned add-ons.

This PRD defines the core product. Individual add-on features are explicitly
excluded, although their installation, isolation, administration, command
exposure, and specialist-agent integration are core requirements.

## Problem

Blue Fission applications currently demonstrate many capable primitives, but
availability is uneven. Some features have stable services and tests without
an operator control, some have visible panels backed by incomplete behavior,
and some exist only as accepted architecture. Users cannot reliably determine:

- what they can use now;
- what requires manual configuration or development knowledge;
- what is safe for production;
- which provider, credential, approval, or tenant boundary applies;
- what happened during an automated or inferred action;
- which capabilities belong to Opus, an add-on, an upstream package, or an
  external service.

The product must turn those primitives into a coherent, inspectable platform
without collapsing package ownership or granting ambient authority.

## Goals

1. Make a new Opus installation understandable and useful without requiring
   source-code inspection.
2. Provide equivalent, policy-consistent web, API, CLI, conversational, and
   automation-agent controls for supported operations.
3. Keep applications, tenants, users, central agents, specialist agents,
   providers, and add-ons isolated by default.
4. Make plans, approvals, executions, failures, retries, and outcomes visible
   and explainable.
5. Support provider-neutral hosted and self-hosted inference without making a
   credential or provider mandatory.
6. Make add-on discovery, validation, installation, activation, update, and
   removal predictable and recoverable.
7. Use Vibe templates for deterministic presentation and approved scaffolding,
   with JenSS available for bounded configuration logic.
8. Produce reproducible evidence for managed hosting, self-hosted releases,
   marketplace artifacts, and Materia integration.

## Non-Goals

- Implement every roadmap item in one release.
- Move add-on domain features into Opus core.
- Give the central agent every installed command.
- Make provider output, hidden reasoning, or generated text an authority.
- Replace package-owned semantics with application-specific aliases.
- Couple the public distribution to Blue Fission hosting or one cloud.
- Treat installation, discovery, or planning as permission to execute work.

## Users And Jobs

### Application Owner

- Create or resume an application setup.
- Define the project, audience, goals, personality, timeline, and delivery
  posture without being blocked by unanswered optional questions.
- Choose whether and how inference providers are connected.
- Review a dry-run plan before any mutation or external action.
- Understand cost, risk, data use, and required approvals.

### Administrator

- Manage users, roles, permissions, tenants, applications, providers, add-ons,
  themes, content, jobs, and settings.
- Use a governed Wise console with the same authorization as other channels.
- Inspect history, failures, degraded dependencies, and recovery options.
- Export, back up, update, roll back, or remove an application safely.

### Application User

- Access only the capabilities granted to their role and tenant.
- Use a private Wise profile for todos, notes, functions, calendars, goals,
  and steps.
- Understand when a response came from deterministic logic, retrieval,
  inference, generation, or a human-approved workflow.
- Review and revoke delegated access where policy permits.

### Developer And Add-On Publisher

- Generate and validate canonical code and add-on structures.
- Use stable mappings, hooks, filters, services, and command contracts.
- Test package and embedded-host behavior without private workstation state.
- Publish versioned artifacts with compatibility, provenance, migration, and
  lifecycle evidence.

### Platform Operator

- Provision isolated applications reproducibly.
- Observe health, capacity, cost, security, backups, incidents, and releases.
- Keep hosted overlays separate from the reusable distribution.
- Provide support evidence without exposing credentials or tenant data.

## Experience Principles

- **Optional intake:** project name is the only required onboarding answer.
- **No surprise execution:** intake, discovery, and generation begin without
  side effects; shadow or dry-run is the default.
- **One operation contract:** web, API, CLI, conversation, and agent calls use
  the same owning service and authorization decision.
- **Explicit boundaries:** the interface identifies tenant, actor, agent,
  provider, capability, approval, and lifecycle state where relevant.
- **Inspectable outcomes:** results distinguish output, diagnostics, evidence,
  confidence, status, and next allowed action.
- **Progressive complexity:** simple applications can skip providers and
  advanced setup; complex applications can configure precise policies.
- **Portable presentation:** add-ons provide theme-neutral UI scaffolding and
  do not require a specific commercial theme.

## Core User Journeys

### Install And Create An Application

1. Start from web or CLI.
2. Validate runtime, storage, dependency, and write-path requirements.
3. Enter the required project name or accept safe optional defaults.
4. Persist a stable application slug and resumable intake session.
5. Optionally answer project type, industry, audience, description,
   personality, agent name, timeline, and delivery phase.
6. Optionally configure an inference provider or choose provider-free mode.
7. Review the application blueprint and a shadow-mode execution plan.
8. Select an explicit button or Wise command to request approved work.
9. Record the request, policy result, approval, execution, and outcome.

No provider call, add-on installation, code write, deployment, or scheduled job
may occur solely because intake started or completed.

### Discover And Install A Capability

1. Inspect currently installed add-ons.
2. Search a marketplace for reviewed packages.
3. Search an Annex directory for compatible external capabilities.
4. Compare compatibility, permissions, maturity, provenance, cost, support,
   and required services.
5. Produce a reviewable installation plan.
6. Approve package acquisition and lifecycle changes separately from runtime
   grants.
7. Validate install, migration, registration, activation, health, and rollback.
8. Decide whether the add-on uses a declared specialist, generated specialist,
   bounded central import, or no agent exposure.

Discovery metadata never grants execution authority.

### Run A Command Or Automation

1. Resolve actor, application, tenant, profile, agent, and correlation context.
2. Discover only commands visible through the same policy used for invocation.
3. Validate inputs, lifecycle, capabilities, side effects, approvals, budgets,
   and provider availability.
4. Run once through the owning Wise or application service.
5. Return structured output, diagnostics, exit status, prompt state, evidence,
   and next action.
6. Pin confirmation continuations to the original scope.
7. Persist an explainable history without storing secrets or unrestricted
   provider payloads.

### Coordinate Central And Specialist Agents

1. The central agent receives the application tool map only.
2. It selects specialist identifiers, not specialist tool implementations.
3. Opus resolves active lifecycle, tenant, profile, provider, and exact grants.
4. Automata orchestrates bounded tasks and merges normalized outcomes.
5. Each specialist executes through its own Wise tool surface.
6. Peer or reverse access requires explicit reciprocal grants.
7. Cancellation, timeout, retry, replacement execution, and teardown preserve
   generation identity and audit state.

### Generate Or Modify Code

1. Select a supported scaffold or Vibe template.
2. Preview variables, target paths, generated diff, validation, and required
   capabilities.
3. Render without side effects for review.
4. Require explicit authorization before writing.
5. Restrict output to the application workspace.
6. Validate syntax, add-on contracts, tests, and rollback information.
7. Record provenance and the exact generation inputs permitted for retention.

Generated output is a proposal until the applicable review and execution gates
complete.

## Functional Requirements

### FR-1 Installation And Bootstrap

- Support documented web and CLI installation.
- Validate PHP, extensions, filesystem, database, cache, and dependency state.
- Initialize storage idempotently and report exact failures.
- Support restart, resume, repair, and rollback without duplicate lifecycle
  effects.
- Keep optional providers and transports non-blocking.

Current status: Partial. Runtime roots and database ordering are implemented;
full web/CLI installation and recovery proof remain.

### FR-2 Resumable Intake And Blueprint

- Persist application slug, display names, answers, defaults, skips, prompt
  version, revision, actor, tenant, and correlation.
- Author the intake as a versioned Vibe prompt with bounded JenSS logic.
- Permit pause, resume, completion, and later amendment.
- Provide explicit “build/plan” controls after intake rather than automatic
  execution.
- Default generated workflows to shadow mode with an administrator override.

Current status: Partial under #95.

### FR-3 Provider-Neutral Inference Setup

- Offer provider-free, hosted, self-hosted, and local choices.
- Collect secrets through a secret-reference boundary, not ordinary settings or
  intake answers.
- Test provider reachability and model capability separately from task
  execution.
- Configure routing, fallback, budgets, timeouts, data-use policy, and allowed
  agents by application and tenant.
- Allow an optional Blue Fission provider without giving it special runtime
  authority.

Current status: Planned through #95, #99, and #29. The present OpenAI-compatible
configuration is not a complete setup experience.

### FR-4 Users, Roles, Permissions, And Tenancy

- Manage users, credentials, sessions, roles, permissions, and explicit denies.
- Scope every protected operation by application and tenant.
- Provide user, tenant, and platform administration views appropriate to the
  operator's authority.
- Apply the same decision to UI visibility, API, CLI, conversation, scheduled
  work, and agent execution.
- Explain denials without leaking hidden capability or tenant metadata.

Current status: Partial.

### FR-5 Add-On Lifecycle

- Discover canonical package definitions without load-time side effects.
- Validate layout, mappings, templates, agent descriptors, and registration.
- Install, activate, suspend, resume, upgrade, deactivate, and remove
  idempotently.
- Execute required lifecycle stages exactly once and aggregate failures closed.
- Expose health, compatibility, migration, and rollback state.

Current status: Partial under #12, #40, and #43.

### FR-6 Themes, Content, Navigation, And Media

- Render Vibe themes with escaped context and explicit trusted markup.
- Support host-owned theme overrides without replacing package resources
  accidentally.
- Manage menus, pages, publication state, media, preview, revisions, and
  recovery through authorized services.
- Keep add-on UI theme-neutral and replaceable.
- Provide accessibility, localization, responsive, and asset-provenance gates.

Current status: Partial; rendering is stronger than administration.

### FR-7 Wise Console And Channel Parity

- Route CLI, browser console, API, conversation, and agent commands through one
  structured host boundary.
- Keep terminal IO, ANSI rendering, prompting, and process exit host-owned.
- Provide a browser TTY with authenticated connection lifecycle, resize,
  reconnect, cancellation, and bounded process/resource use.
- Maintain one-to-one command and UI controls for supported operations or
  explicitly document channel exceptions.

Current status: Partial under #13.

### FR-8 Agent Composition

- Configure a central agent and optional add-on specialists without constructing
  providers during discovery.
- Support multiple provider profiles per agent.
- Resolve exact, non-transitive command capabilities per request.
- Support lifecycle, bounded delegation, cancellation, concurrency fences,
  failure recovery, and auditable normalized outcomes.
- Keep specialist and central profiles private unless explicitly delegated.

Current status: Partial under #29 and #99.

### FR-9 Conversation And Learning

- Ship deterministic, versioned command and profile examples.
- Keep observation and learning in shadow/review mode by default.
- Separate route seeds, model artifacts, memory, and promotion candidates.
- Require consent, provenance, scope, review, and activation state.
- Never capture secrets, provider payloads, or private conversations by default.

Current status: Partial under #99.

### FR-10 History, Explainability, And Approval

- Provide a unified timeline for user, command, workflow, generation, add-on,
  provider, and agent activity.
- Record actor, scope, grant, policy, evidence references, reason codes,
  approval, status, retry, cancellation, rollback, and retained artifacts.
- Distinguish deterministic decisions, retrieved evidence, machine-learning
  classification, inference, generation, and human approval.
- Expose redacted views appropriate to users, administrators, auditors, and
  support operators.
- Permit replay only through a new authorization decision.

Current status: Partial primitives; no complete operator surface.

### FR-11 Settings And Secrets

- Compose package, application, tenant, and principal settings deterministically.
- Reapply authoritative denies and privacy rules after extension filters.
- Validate settings before activation and show source/effective value without
  exposing secret content.
- Store credentials through references suitable for local and managed hosts.
- Audit changes and support rollback.

Current status: Partial.

### FR-12 Updates And Maintenance

- Inspect available core, add-on, theme, and dependency updates.
- Verify provenance, signatures, checksums, compatibility, migrations, and
  licenses.
- Support staging/dry-run, maintenance state, backup, update, health check,
  rollback, and recovery.
- Never update from an unreleased mutable branch in production.

Current status: Planned under #104 and #105.

### FR-13 Managed Hosting And Portability

- Provision isolated applications with explicit region, lifecycle, quota,
  billing, backup, recovery, export, deletion, and support contracts.
- Keep hosted overlays outside reusable package semantics.
- Allow an application to leave managed hosting with documented export and
  restore behavior.

Current status: Planned under #103.

### FR-14 Marketplace And Annex Directory

- Operate package and capability discovery as a dedicated Opus application.
- Keep add-on artifacts distinct from Annex scripts, workflows, manifests, and
  remote capability records.
- Support public, private, paid, free, internal, deprecated, and unavailable
  listings.
- Separate publisher review, entitlement, install approval, runtime grants, and
  execution authority.

Current status: Planned under #105.

### FR-15 Developer Platform And Conformance

- Publish add-on and code generators, validators, examples, hooks, filters,
  command contracts, and compatibility policies.
- Use exact focused and full-suite validation commands.
- Tie releases to an immutable Materia integration snapshot.
- State optional, degraded, unsupported, and unknown capability states exactly.

Current status: Partial under #107 and #110.

## Non-Functional Requirements

### Security

- Deny access by default.
- Revalidate authorization after extension filters.
- Keep secrets and raw provider payloads out of logs, hooks, maps, and public
  catalogs.
- Fence tenant, principal, agent, continuation, and execution identities.
- Require explicit approval for privileged or destructive actions.

### Reliability

- Lifecycle and installation operations are idempotent.
- Commands execute once and return structured terminal states.
- Retries cannot duplicate authoritative mutations.
- Cancellation targets an exact execution generation.
- Degraded optional services do not break unrelated core paths.

### Portability

- Support PHP 8.2+ within the declared matrix.
- Avoid operating-system-specific process assumptions in core contracts.
- Keep source and distribution installs behaviorally equivalent.
- Keep hosted deployment policy outside the reusable package.

### Performance

- Avoid constructing providers, interpreters, or add-on runtimes during
  discovery.
- Bound command, provider, queue, and generation work by timeout and budget.
- Publish representative latency, throughput, concurrency, and resource-use
  evidence before production claims.

### Accessibility And Localization

- Meet an explicit accessibility baseline for public and administrative
  surfaces.
- Support keyboard operation, readable status, non-color-only meaning, and
  reduced-motion preferences.
- Define localization, time-zone, date, and content-direction behavior before
  the public self-hosted release.

### Observability

- Provide stable health, readiness, diagnostic, audit, metric, and trace
  records.
- Preserve evidence freshness independently from approval or execution
  authority.
- Provide support bundles with redaction and tenant boundaries.

## Success Measures

Initial measures are release gates rather than adoption targets:

- A clean installation reaches a usable application without source patches.
- An operator can complete or skip intake, configure no provider, and still use
  deterministic application functions.
- One authorized operation produces behavior-identical results through web and
  Wise channels.
- One central-to-specialist delegation proves no central, peer, profile, or
  tenant tool leakage.
- One generated scaffold can be reviewed, written, validated, and rolled back.
- One add-on can install, activate, update, deactivate, and remove repeatedly
  with exact readiness results.
- Every release-critical action appears in an explainable, redacted timeline.
- Backup and restore recover a representative application within the declared
  objective.
- A public distribution installs without local paths or unpublished packages.
- Marketplace discovery, entitlement, install approval, and runtime grants are
  proven as separate transitions.

Commercial, adoption, retention, and service-level targets belong to managed
product planning and must be added with an owner and measurement source.

## Release Gates

### Controlled Internal Use

- Required dependency graph resolves from reviewed sources.
- Core tests, lifecycle checks, and security audit pass.
- Manual setup and known limitations are documented.
- Operators accept that several administration and recovery paths are partial.

### Managed Alpha

- Complete application/tenant provisioning and deletion.
- Complete provider, user, role, settings, secrets, history, approval, health,
  backup, and recovery administration.
- Run production-shaped internal workloads with support ownership.

### Public Self-Hosted Release

- Satisfy #104 and publish an immutable #107 conformance snapshot.
- Remove private and mutable release dependencies.
- Prove web and CLI install, update, rollback, backup, restore, export, and
  representative add-on lifecycle on the supported matrix.
- Publish security, support, compatibility, deprecation, and migration policy.

## Dependencies And Ownership

- DevElation: primitives, data, hooks, filters, and service utilities.
- BlueCore: application framework and add-on lifecycle foundation.
- Wise: command parsing, resources, and headless command results.
- Automata: automation-agent orchestration and delegation.
- Synthetiq: conversation, intent, profile, and learning semantics.
- Vibe/Vibrato: authored template and generation semantics.
- JenSS/Jenerator: configurable script and validation semantics.
- Annex/Synematic: interoperability and packet semantics.
- Presence/Hoom: optional identity capabilities composed by the host.
- Opus: application lifecycle, policy, administration, composition,
  presentation integration, and product evidence.

Future Hestia, The Mix, Cogito, and broader ecosystem concepts may consume or
extend these contracts. Hestia and The Mix remain candidate applications or
add-ons until their ownership and public/private boundaries are established.
Cogito is decision/guidance-first with optional conversation at the Opus
boundary. Coeus remains an exploratory, read-only evidence adapter under #117.
These concepts do not become current core commitments through implication.

## Risks

- Visible panels can be mistaken for complete administration.
- Service classes can be mistaken for available product features.
- Provider-specific legacy configuration can be mistaken for a required
  provider.
- Add-on discovery can be conflated with authorization.
- Generated output can be mistaken for reviewed application state.
- Mutable VCS dependencies can prevent reproducible public releases.
- Broad technology claims can obscure whether a feature uses deterministic
  automation, machine learning, retrieval, inference, or generation.
- Product breadth can delay end-to-end proof of the core application journey.

## Open Product Decisions

- Minimum core content/publishing scope versus add-on ownership.
- Supported database and cache matrix for the first public release.
- Browser TTY transport and process-isolation model.
- Secret-store interfaces for self-hosted and managed installations.
- Default provider-free behavior and optional Blue Fission inference service.
- Accessibility and localization baselines.
- Update channel, signing authority, support window, and telemetry policy.
- Commercial marketplace review, entitlement, refunds, and support boundaries.

These decisions require explicit owners and issues before they become roadmap
commitments.

## References

- [Product specification](PRODUCT.md)
- [Roadmap](ROADMAP.md)
- [Technical specification](SPEC.md)
- [Terminology policy](docs/terminology.md)
- [Product and ecosystem program](https://github.com/BlueFissionTech/opus/issues/102)
- [Product definition work](https://github.com/BlueFissionTech/opus/issues/113)
