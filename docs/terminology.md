# Capability Language

## Purpose

Opus documentation and interfaces describe the capability being used instead
of relying on `AI` as a blanket product category. Precise language makes
behavior, ownership, risk, and evidence easier to evaluate.

## Preferred Terms

| Term | Use when |
| --- | --- |
| Automation | Rules or software execute a defined process. State whether work is deterministic, scheduled, event-driven, or approval-gated. |
| Inference | A model produces a prediction, completion, classification, extraction, or other result from input. Name the specific result where possible. |
| Machine learning | A model is trained, evaluated, or updated from data. Do not use it for ordinary rules or prompt execution. |
| Generation | Software creates text, code, media, templates, plans, or structured artifacts. State whether the source is deterministic templates, inference, or both. |
| Comprehension | A bounded feature interprets, summarizes, extracts, or relates information. Name the operation and its evidence limits. |
| Retrieval | A system locates records, documents, facts, or context. Distinguish retrieval from generation. |
| Classification | A model or rule assigns one of a declared set of labels. Document unknown and confidence behavior. |
| Decision support | A system ranks or explains options for a human or policy authority. It does not imply execution authority. |
| Insight | A presented finding derived from identified data or evidence. State the source, freshness, and uncertainty. |
| Orchestration | A coordinator selects, sequences, delegates, or combines bounded work. It does not imply unrestricted autonomy. |
| Automation agent | A scoped runtime that acts through an explicit profile, capability map, policy, and lifecycle. |
| Central agent | The application coordinator operating through the root Opus capability map. |
| Specialist agent | An add-on-scoped runtime operating through that add-on's resolved capability map. |
| Inference provider | A hosted, self-hosted, or local service that executes model inference. |
| Provider profile | A secret-free reference to provider, endpoint, model, routing, budget, retention, and policy configuration. |

## Writing Rules

- Name the actual operation: “classifies intent,” “generates a Vibe scaffold,”
  “retrieves documents,” or “coordinates specialist agents.”
- Separate deterministic automation, machine-learning behavior, inference,
  retrieval, and generation.
- Distinguish a recommendation from a decision and a decision from authorized
  execution.
- State whether a provider is optional, hosted, self-hosted, local, or
  unavailable.
- State which component owns the behavior. Opus composition does not transfer
  package ownership.
- Do not use “smart,” “intelligent,” “autonomous,” or “self-improving” without
  describing the bounded behavior and evidence.
- Do not imply that generated content is correct, approved, deployed, or
  authoritative.
- Keep provider payloads, hidden reasoning, credentials, and protected data out
  of public explanations and hook payloads.

## Compatibility Exceptions

Some existing public symbols contain `AI` and cannot be renamed as a copy
change. Current examples include `AIResource`, the `ai` Wise command group,
`common/config/ai.php`, and third-party provider or product names.

These names remain compatibility surfaces until a separate migration:

1. introduces a precise replacement or alias;
2. updates discovery and authorization maps;
3. documents deprecation and compatibility windows;
4. adds tests for existing callers;
5. updates add-on and upstream contracts;
6. removes the legacy name only in a later reviewed release.

Documentation may identify such a symbol as a legacy compatibility name, but
must describe its behavior using precise capability language.

## Interface Examples

Avoid:

- “Configure AI”
- “AI-powered application”
- “The AI decided”
- “Self-improving system”

Prefer:

- “Configure an inference provider”
- “Build an application with automation, retrieval, and generation”
- “Decision support ranked these options; approval is still required”
- “Review a machine-learning promotion candidate”

## Review Checklist

- Does the text name a real capability?
- Does it identify the relevant provider, policy, data, or evidence boundary?
- Does it avoid implying authority from discovery, inference, or generation?
- Does it preserve compatibility-sensitive public names?
- Does it state unknown, partial, or unavailable behavior directly?
