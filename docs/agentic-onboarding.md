# Agentic Onboarding

Opus application onboarding is a resumable intake process, not an execution trigger. Creating, answering, skipping, pausing, resuming, or completing an intake session cannot install add-ons, invoke providers, generate code, publish workflows, or mutate the application beyond the intake record itself.

## Session contract

- Project name is the only required answer.
- Application slugs are stable within an application or tenant scope.
- Project and agent display names remain editable independently from the slug.
- Explicit answers, defaults, skipped fields, and unanswered fields are stored separately.
- Prompt version, actor, tenant, correlation, revision, and transition timestamps are retained.
- Completed sessions may be resumed when the operator wants to provide more context.

The initial defaults describe a general technology and communications web application for business-to-consumer audiences, with professional and informative agent behavior. Defaults are resolved for planning but do not masquerade as explicit operator answers.

## Boundaries

`ApplicationIntakeService` owns host-neutral transitions and delegates persistence through `IApplicationIntakeRepository`. The SQL adapter stores normalized session state in `application_intakes`.

Vibe prompt execution, JenSS branching, Wise commands, HTTP/admin presentation, plan generation, marketplace discovery, and workflow execution are separate capabilities. Those layers may consume this contract but must not bypass it or attach side effects to intake transitions.

## Migration

Run the standard database delta command before resolving the SQL repository in a fresh application. Existing applications do not receive an intake session automatically.
