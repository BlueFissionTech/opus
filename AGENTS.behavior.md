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

## Weekday Repository Triage

- Maintain one continuing heartbeat task for repository triage so each run
  returns to the same task history. Run Monday through Friday at a coordinated
  15-minute increment between 08:00 and 17:00 in `America/New_York`, favoring
  noon. The current preferred slot is 12:15. Coordinate changes through Keryx
  with Pelorus and affected sibling repositories before moving the cadence.
- If the heartbeat is missing, disabled, duplicated, or collides with another
  ecosystem automation, report it to the operator and request or perform its
  re-establishment when authorized. Prefer updating the existing heartbeat to
  creating another task.
- At the start of each run, inspect and respond to unread Keryx messages and
  joined discussion rooms. Acknowledge completed coordination so it does not
  recur, route package-specific work to the responsible upstream or sibling,
  and send concise completion or blocker updates to affected repositories and
  rooms.
- Next, triage GitHub review comments, pull requests, issues, CI, and associated
  project items. Make bounded requested updates on an issue branch, stage new
  work in focused pull requests, and close items only after their completion or
  merge is verified. Ask the operator when approval, product direction, access,
  or a consequential choice is required.
- Operator direction has first authority. When priorities or resourcing are not
  explicit, request guidance from Pelorus through Keryx before selecting among
  competing tasks. Push back on work outside Opus platform responsibilities and
  ask for its rationale rather than absorbing another package's domain.
- Continue processing new messages delivered during the run until actionable
  work is exhausted or a human decision is required. Keep the batch small
  enough for human review; use remaining capacity for safe refactors, tests,
  examples, documentation, and measured optimizations rather than speculative
  features.
- When no Pelorus, Keryx, GitHub, or project work remains, finish reviewable
  work already present in active worktrees. Commit and push only when authorized
  and validated. Use Keryx-approved orchestration to stop stale test containers,
  avoid unrelated workloads, tidy temporary artifacts, reconcile worktrees, and
  leave the central checkout on an updated protected branch without direct
  commits to that branch.
- Periodically review enduring source and history patterns and keep
  `AGENTS.standards.md`, specifications, architecture, and roadmap documents
  aligned with evidence. Treat creative, philosophical, product-direction, and
  major standards decisions as Pelorus or operator decisions; do not create
  documentation churn merely to fill a quiet run.
- Use an elevated sandbox for credential-dependent GitHub operations when the
  environment permits it. Keep Keryx as the gateway for cross-repository,
  GitHub, and Docker work. If Keryx is unavailable, start it in the background
  through the repository-provided helper when authorized; never replace that
  path with raw Docker commands or expose secrets.
