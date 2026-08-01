# AGENTS.behavior.md - Project-Specific Behaviour Rules

This file contains repository-specific operational preferences that complement
`AGENTS.md` and `AGENTS.standards.md`. It must not weaken the DevElation coding
and review standards in `AGENTS.standards.md`.

## Repository Role

- Treat this repository as the Opus application platform layer in the Blue
  Fission ecosystem.
- Keep Opus work centered on platform composition, application runtime
  contracts, add-on lifecycle, command surfaces, UI/resource integration, and
  project-level orchestration.
- Use the repository history, sibling library conventions, and upstream
  dependencies to guide design before introducing a new pattern.
- Prefer Blue Fission packages, idioms, and in-house design patterns over
  third-party dependencies when the local ecosystem already owns the
  capability.

## Package Boundaries

- Do not frame features as catering to dependent packages. If a dependent
  project reveals a valid need, express the work as a general Opus capability
  within this repository's responsibility.
- Avoid naming dependent packages in GitHub issues, pull requests,
  professional comments, or discussion-room guidance unless a concrete
  compatibility note makes that unavoidable.
- Name upstream dependencies when they shape implementation, limitations,
  capacity, acceptance criteria, or validation evidence.
- Keep APIs and behavior signatures broadly useful without forcing abstraction
  for its own sake. Prefer intuitive, prescriptive Blue Fission-style surfaces
  over one-off coupling or speculative generality.
- When a request originates from one consumer, design the smallest durable
  feature that still makes sense for other future consumers in the same
  capability area.

## Formatting And Code Style

- Use PHP 8.2-compatible syntax and strict types for new PHP files unless an
  existing file intentionally follows an older house style.
- Apply `AGENTS.standards.md` for DevElation primitive, helper, behavioral,
  connection, data, and service decisions.
- Keep public methods camelCase and class properties consistent with the
  surrounding file. Add type hints and return types where practical.
- Keep comments sparse and useful. Do not leave commented-out legacy code; use
  version history for abandoned alternatives.
- New capabilities should be package-owned, reusable, and composable through
  stable contracts. Avoid deep coupling to a single consumer or workflow.

## GitHub Hygiene

- Keep GitHub issues, comments, and PR text sanitary, trackable, and
  collaborator-facing.
- Do not mention local-only workflow tools, local filesystem paths, temporary
  artifact paths, or workstation details in GitHub-facing text.
- Capture scratchpad detail, local evidence, and temporary coordination notes
  in private coordination messages or ignored artifacts instead of GitHub.
- Create GitHub issues for discrete new needs and keep PRs focused on the
  correct target branch.
- Leave descriptive questions or approval conditions when a PR has moved from
  clear execution into human judgment.

## Discussion And Messaging

- Be liberal about accepting intentional discussion invitations.
- Acknowledge closed or completed coordination messages so they do not loop back at
  the end of later turns.
- Collaborate actively in discussion rooms: state this repository's
  responsibilities, call out upstream constraints, explain caveats, share
  relevant capabilities, and ask precise questions where boundaries are
  unclear.
- Keep discussion-room language professional and package-neutral. Do not
  reduce work to a dependent request; describe shared goals, contracts,
  readiness, and responsibility boundaries.
- When useful, share Blue Fission philosophy and DevElation-style patterns to
  help other repositories align with the ecosystem direction.

## Execution Style

- Prefer real tests with actual data. When data is missing, request or
  generate representative data through appropriate collaborators or fixtures.
- Coordinate Docker and port usage through the approved orchestration paths
  before running service workflows.
- When orchestration or harness friction appears repeatedly, create or suggest a
  focused upstream improvement instead of only noting it in chat.
- After coding work is complete, use remaining time to tighten tests, record
  concise artifact logs, clarify issues, or advance relevant discussion-room
  topics, stopping when further work becomes speculation better suited for
  human review.
