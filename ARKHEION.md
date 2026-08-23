# Arkheion Capability Catalog

## Purpose

Arkheion capabilities begin as bounded Opus add-ons. A capability becomes an
independently deployable service only when independent scaling, isolation,
release cadence, reuse, or commercial demand justifies the operational cost.
Installation or route availability alone does not establish production
readiness.

This catalog is an Opus host-level readiness record. Capability semantics stay
with their owning packages. Opus owns application registration, add-on
discovery and lifecycle, command exposure, themes and routes, health and
readiness, integration wiring, and bounded application context.

## Evidence And Maturity

The catalog uses these maturity labels:

- **stable**: released, versioned, contract-tested, operationally proven, and
  supported for the declared compatibility range;
- **evolving**: package-owned with meaningful tests and integration proof, but
  still changing before a stable contract;
- **experimental**: executable proof exists, but compatibility, lifecycle, or
  operational behavior remains incomplete;
- **planned**: an approved capability boundary exists without executable proof;
- **unknown**: available evidence cannot establish ownership or readiness.

The authoritative source order is a released package, an exact package source
reference in `composer.lock`, versioned Opus source, and finally an observed
runtime installation. Runtime files beneath `addons/` are ignored by Git and
are not release evidence by themselves.

## Current Catalog

Snapshot: Opus `17cc2a1`, 2026-08-22. A lock or capability change must refresh
this table before release.

| Capability | Current source | Maturity | Source-of-truth and duplicate status | Current disposition |
| --- | --- | --- | --- | --- |
| Hoom | `bluefission/opus-addon-hoom` at `98db9a8` | evolving | Composer package is authoritative. Runtime directories named `hoom` and `opus-addon-hoom` represent the same metadata identity; coexistence blocks release readiness until one migration path is selected. | Remain a package-owned add-on. Identity and access semantics stay with Hoom and Presence. |
| Kapsle | `bluefission/opus-addon-kapsle` at `4e39482` | experimental | Composer package is authoritative. Runtime directories named `kapsle` and `opus-addon-kapsle` represent the same metadata identity; coexistence and unresolved autoload diagnostics block release readiness. | Remain a package-owned add-on. Tenancy protocol semantics stay with Kapsle and Annex. |
| Aimos | runtime installation only | unknown | No versioned Opus source or declared add-on package establishes a release contract. | Keep application-local until package ownership, tests, and lifecycle proof exist. |
| BasicContact | runtime installation only | unknown | No versioned Opus source or declared add-on package establishes a release contract. | Keep application-local; do not infer a messaging service contract. |
| Cogito | runtime installation only | unknown | No versioned Opus source or declared add-on package establishes a release contract. | Keep application-local until provider-neutral agent contracts are package-owned. |
| ForeUP | runtime installation only | unknown | No versioned Opus source or declared add-on package establishes a release contract. | Keep application-local as an external-system adapter candidate. |
| Presentations | runtime installation only | unknown | No versioned Opus source or declared add-on package establishes a release contract. | Keep application-local until generation inputs, outputs, and storage policy are explicit. |
| SageMaker | runtime installation only | unknown | No versioned Opus source or declared add-on package establishes a release contract. | Keep application-local as a provider adapter candidate. |
| Smart Responder | runtime installation only | unknown | No versioned Opus source or declared add-on package establishes a release contract. | Keep application-local; do not merge its contract with contact intake without a reviewed boundary. |
| Students | runtime installation only | unknown | No versioned Opus source or declared add-on package establishes a release contract. | Keep application-local as a domain capability. |
| Synematic add-on | runtime installation only | unknown | The add-on has no versioned Opus source. The direct `bluefission/synematic` dependency does not make the runtime add-on release-ready. | Keep application-local until it exposes a package-owned gateway contract. |

No capability in this snapshot is service-ready. Hoom and Kapsle are the only
package-owned add-on candidates, and both still use unreleased `dev-main`
constraints in the Opus root graph.

## Shared Opus Host Contract

Every cataloged capability must use the same host boundary:

- Composer owns package installation and autoloading; `type: opus-addon` owns
  placement beneath the application `addons/` directory.
- BlueCore owns discovery records and lifecycle persistence. Opus owns the
  application commands and readiness results that invoke those contracts.
- Installation, activation, deactivation, and uninstallation return structured
  results and remain safe to retry at their documented boundaries.
- An active add-on registers routes, themes, commands, and other contributions
  exactly once. Inactive or unavailable add-ons register nothing.
- Wise is the command processor boundary. Opus consumes typed command requests
  and results without reimplementing parsing or executing a result twice.
- Provider-neutral central and specialist agent composition remains an Opus
  lifecycle concern; Automata owns delegation and Synthetiq owns conversational
  profiles and bounded context behavior.
- Vibe and Vibrato own template syntax and execution. Opus owns trusted context
  policy, template selection, and safe output placement.
- Annex and Synematic own interoperability contracts. Opus exposes declared
  capabilities without inventing protocol semantics.
- Health and liveness prove the host process and required persistence state.
  Optional providers and specialist capabilities belong in readiness details,
  not process liveness.
- Configuration names are documented without secret values. Process-injected
  values take precedence over dotenv fallback.

## Promotion Gates

### Embedded Add-On To Package-Owned Add-On

- one authoritative repository and package identity;
- canonical add-on layout, namespace, metadata, and direct dependencies;
- unit, lifecycle, package-install, optimized-autoload, and security-audit proof;
- idempotent migrations, generators, hooks, and explicit data-retention policy;
- versioned Opus and upstream compatibility range;
- removal or documented migration of duplicate runtime copies.

### Package-Owned Add-On To Service-Ready Module

- transport-neutral inputs, outputs, events, commands, schemas, and reason codes;
- explicit authentication scopes, tenant/isolation model, data classification,
  retention, rate limits, retries, timeouts, and failure behavior;
- versioning, migration, backup, rollback, and decommission contracts;
- provider-independent degradation behavior and a reference integration flow;
- health, readiness, logs, metrics, traces, and audit/provenance references.

### Service-Ready Module To Internal Service

- immutable source reference and review-gated promotion;
- independently deployable, observable, reversible, and costed runtime profile;
- required environment key names, ports, resource envelope, and ownership;
- operational proof showing that independent deployment creates measurable
  scaling, isolation, cadence, or reuse value.

### Internal Service To Externally Supported Service

- reviewed SLA, support, security, privacy, and commercial terms;
- multiple supported integrations or validated paid demand;
- compatibility, migration, incident, rollback, and end-of-life policies.

## Current Readiness Packet

The following Opus gates precede service extraction:

- lifecycle acceptance and repeat safety: issues #12, #40, and #43, with PR #53;
- environment precedence and secret-safe bootstrap: issue #38, with PR #54;
- Composer-installed host and package parity: issue #50, with PR #55;
- contextual template policy: issue #48, with PR #58;
- safe integration definition loading: issue #19, with PR #59;
- dependency-aware Wise registration and authoritative catalog adoption: issues
  #39 and #61, with PR #60;
- reproducible asset ownership: issue #11, with PR #62;
- credential-free base dependency installation: issue #56;
- verified initialization, runtime health, and launch profiles: issues #25,
  #20, and #31.

Promotion remains review-gated. Unknown capability state is not equivalent to
failure, but it cannot be used as release evidence.

## Catalog Update Checklist

For every catalog change, record the capability id, owner, maturity, exact
source tag or commit, freshness date, compatibility range, commands or API,
schemas and errors, security and tenancy policy, persistence and migration
policy, readiness evidence, deployment profile, approval owner, and next gate.
Do not promote a row when any required field is inferred rather than verified.
