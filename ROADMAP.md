# Opus Product Roadmap

This roadmap defines the product lines, release sequence, ownership boundaries,
and evidence required to operate and publish Opus. GitHub issue
[#102](https://github.com/BlueFissionTech/opus/issues/102) is the program
tracker.

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

### Phase 1: Managed Platform Alpha

- Provision and remove an isolated application through an idempotent workflow.
- Establish tenant/application identity, lifecycle, quotas, audit, backup,
  recovery, export, and deletion.
- Exercise the admin surface, Wise command parity, central agent, one specialist
  add-on, and dry-run automation.
- Define support, incident, metering, billing, and service-level boundaries.
- Run production-shaped internal workloads before external availability.

### Phase 2: Marketplace and Directory Alpha

- Publish one reviewed add-on release with provenance, signature,
  compatibility, license, maturity, and lifecycle evidence.
- Publish one Annex-compatible directory entry with a versioned manifest, trust
  references, freshness, and capability metadata.
- Prove public and organization-private visibility without metadata leakage.
- Prove search, inspection, policy review, install, update, rollback, withdraw,
  and deprecate paths.
- Keep discovery separate from execution and privilege grants.

### Phase 3: Arkheion First Service Wave

- Use the shared promotion contract for the first approved add-on services.
- Deploy each service as a dedicated Opus installation on OCI.
- Prove API, Wise command, specialist-agent, and Annex manifest compatibility
  where those surfaces are declared.
- Prove TLS, secrets, persistence, tenancy, rate limits, observability, backup,
  rollout, rollback, and disaster recovery.
- Expand to later Arkheion services only after their prerequisites and current
  portfolio priority are explicit.

### Phase 4: Self-Hosted Public Release

- Freeze a supported upstream compatibility matrix.
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

### Phase 5: Production Expansion

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
