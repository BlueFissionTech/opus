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

Versioned question rendering is described below. JenSS branching, Wise commands,
HTTP/admin presentation, plan generation, marketplace discovery, and workflow
execution remain separate capabilities. Those layers may consume this contract
but must not bypass it or attach side effects to intake transitions.

## Versioned Vibe questions

`ApplicationIntakePromptRenderer`, registered as the native `intake.prompt`
service, renders the package-owned `resource/prompts/intake/intake-1.0.vibe`
through `VibeThemeRenderer` and the installed Vibrato Reader. Call `render()`
before intake to ask for the project name, or pass an already-authorized
`ApplicationIntakeSession` to ask its remaining optional questions. The exact
stored prompt version selects an allowlisted template; unknown versions fail
with `intake_prompt_version_unsupported`, without a fallback or a file lookup
derived from the supplied version. Rendering failures become `intake_prompt_unavailable`.

Answered and skipped fields are omitted, while defaults remain distinct from
answers. Paused and completed sessions show explicit resume guidance, not new
questions. Rendering does not persist, transition, resume, generate a plan or
construct a provider. Clients continue to obtain and change private intake data
through the existing authorized host boundary. This service is not a new HTTP endpoint,
authentication mechanism, conversational profile or transport.

Only canonical status and unanswered-field flags enter Vibe. Private answers,
defaults, project names, identities and correlation values stay outside the
interpreter and its global runtime signals. Do not interpolate intake answers
into Vibe source. The trusted prompt uses native conditionals, syntax validation,
a null LLM and `run_backend=false`; that flag is not a sandbox for arbitrary
Vibe scripts. No uploaded script, JenSS block or dynamic template is accepted.

No database migration or dependency change is needed for this renderer. Existing
version `1.0` records retain their version; future versions require an explicit
template and compatibility tests. Run the prompt, session, renderer and native
registration tests plus the full suite before promotion. This is a question
runtime, not proof of complete web/CLI onboarding, database restart/concurrency,
native persistence replacement or installed-host readiness.

## Extension points

- `opus.intake.defaults` filters an envelope containing `defaults` and read-only `context`. Only the filtered `defaults` array is consumed; project identity, tenant, slug, and prompt version remain authoritative inputs.
- `opus.intake.session.transitioned` runs after a successful persistence write with a payload containing `transition` and the normalized `session`. Supported transition values are `started`, `answered`, `skipped`, `paused`, `resumed`, and `completed`.

Idempotent start reads do not emit a transition action. Extensions must handle their own failures; an exception from a post-persistence action does not undo the completed write.

## Migration

Run the standard database delta command before resolving the SQL repository in a fresh application. Existing applications do not receive an intake session automatically.
