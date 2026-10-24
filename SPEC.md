# Opus Framework Specification

## Add-on boot diagnostics

Canonical add-on registration failures retain the selected package identity,
host-owned phase and reason, and whether effects may have started. The diagnostic
never includes paths, exception text, provider output or a previous exception.
Repeated engine dispatch returns the same failure without replaying package code.
Diagnostics are host-local observations, not activation state or retry authority.
Baseline mappings must load before add-on routes, themes and services, allowing
intentional overrides through the real engine bootstrap order.

## Minimal application profiles

Explicit `OPUS_PROFILE=api` and `OPUS_PROFILE=dashboard` select a small HTTP
surface through the existing BlueCore engine. The default `platform` profile
retains the full application. Minimal profiles must not register agent tools,
provider integrations, administration mutations or activated add-ons implicitly.
`OPUS_ADDONS` is an explicit selection of installed, active add-ons; missing
selections fail startup before any selected entrypoint runs.

API health, readiness and information routes must boot without a database or
optional provider. The dashboard uses the shared authentication gateway and
package template boundary, with database readiness distinct from liveness.
Configuration PHP scripts must parse before any configuration script executes.
Failed bootstrap must return a stable JSON 503 without exception text or HTML.
These profiles require HTTP, denial, successful-session, malformed configuration
and unavailable add-on tests; production database initialization and clean-volume
Compose verification remain separate release evidence.

## Purpose

Opus is the application platform layer for Blue Fission products. The next
version should run its command and automation center through Wise, delegate
agent orchestration to Automata, use Vibrato for authored templates and
generation flows, and keep add-ons isolated behind their own package-owned
activation and service contracts.

Wise is the central command kernel for human and agent invocation. Framework
console commands should remain capability-first and expose stable contracts
that Wise can discover, route, validate, and report without binding the
platform to a single workflow or consumer.

## Scope

Framework owns:

- runtime host contracts and readiness reporting
- add-on and theme lifecycle orchestration
- backend command registration and invocation surfaces
- application-level integration of first-party Blue Fission libraries
- frontend asset bundling and Reactor-oriented presentation entry points
- production validation contracts for tests, build, and release readiness

Framework does not own:

- interpreter grammar or parser behavior
- reusable automation, inference, or machine-learning algorithms below the
  application platform layer
- external service credentials or deployment secrets
- consumer-specific application features

## Current Integration State

- DevElation, Automata, BlueCore, Chronicler, SimpleClients, and Synthetiq
  resolve through tagged Packagist releases. Wise, Vibrato, Presence,
  Synematic, and the currently installed add-on packages use explicit root VCS
  repositories.
- The Composer lock resolves these packages on PHP 8.2 while the terminal
  WebSocket transport remains optional for hosts with compatible dependencies.
- Reactor is declared as the Blue Fission frontend package for the JavaScript
  presentation layer, with the existing local dashboard modules still present
  until they are migrated feature by feature.
- App registration already injects the Wise command processor and BlueCore
  add-on manager. The Wise kernel remains the correct owner for shell routing,
  bridge execution, virtual root behavior, and interpreter dispatch.
- Opus now exposes a Vibrato-backed generation service for syntax validation,
  deterministic rendering, and bounded file output inside the application
  workspace.

## Runtime Contract Proof

The runtime contract proof describes the platform surface through a manifest,
fixture payload, executable scripts, and optional target scripts. It is exposed
through capability-first commands:

- `contract proof`
- `contract targets`
- `contract validate`

The proof remains a framework-level validation asset. Its source format is an
implementation detail; the durable contract is the host, lifecycle, readiness,
resource, and feedback behavior being exercised.

## Target Architecture

### Native Strategy Runtime

An opt-in runtime factory must compose the installed Automata strategy router
and host-configured Intelligence advisor behind `IAgentRuntimeFactory`.
Availability probes must not construct runtimes or request authorization.
Every execution must obtain fresh host-owned authorization, bind the subject
to the descriptor and tenant, and keep task content separate from route policy.
Deterministic routing is the default; learned/generative escalation requires
explicit host configuration. Results expose business output and bounded usage
and routing evidence without copying provider diagnostics or authority packets.
Synchronous routing must not claim background interruption or module teardown.

### HTTP Authentication Boundary

Authentication gateways must stop route preparation and execution when identity
validation or session establishment fails. A redirect or a false return alone
does not cancel dispatch. Both ordinary and administration HTTP authentication
use this host boundary and return a stable 401 without exposing provider errors.
Authentication does not establish administrator roles or tenant permissions;
the remaining native authorization and administration work belongs to #123.

### Runtime Availability

Runtime availability is separate from the persisted governor lifecycle. The
composition service must support an authorized, read-only inspection that uses
local factory configuration without constructing or invoking a runtime. Cached
runtimes must remain subject to current availability checks for new work, while
authorized suspend, stop and cancel operations retain access to an existing
runtime during an outage. Cleanup without a cached runtime still requires an
available factory before construction. A failed probe must
report a stable unavailable reason, preserve lifecycle state, and permit a later
explicit retry without leaking exception text. See
[runtime availability](docs/runtime-availability.md) for the scoped contract and
the remaining recovery and operator-surface work under #125.

### Command Kernel

Wise should be treated as the central command kernel. Opus should register the
resources, services, add-on commands, and user-facing surfaces that Wise can
route to, but Opus should not duplicate Wise parsing, bridge dispatch, memory,
or virtual filesystem responsibilities.

Acceptance criteria:

- Opus command surfaces resolve through Wise-owned command contracts.
- CLI, chat, and programmatic callers invoke the registered
  `ICommandProcessor` through one Opus host adapter.
- Each request or continuation produces one `CommandResult`; callers render
  its output, diagnostics, exit status, and confirmation state without
  executing the result again.
- Terminal input, prompting, ANSI output, transport replies, and process
  termination remain host concerns outside Wise.
- Console managers expose small, testable operations that Wise can call.
- Script execution routes through Wise bridges rather than bespoke Opus
  interpreters.
- Backend generation commands return structured operation, status, exit-code,
  output, and diagnostic data without writing directly to terminal streams.
  The invoking host owns presentation and process termination.

The optional WebSocket terminal uses a separate `CommandHost` per connection.
It accepts complete command or confirmation frames, never keystrokes or shell
commands. Host authentication supplies actor, tenant and profile on every request;
client frames cannot supply authority. Identity is bound at opening, permissions
are refreshed, and only that connection's outstanding continuation may be resumed
once. Disconnect/error releases the host and pending token; no child processes or
polling timers are created. Session/frame limits bound transport-owned state.
Long-running commands must use host-owned bounded/queued execution. The standalone
listener requires a trusted host bootstrap and binds to loopback by default.
Optional transport absence and a missing authorization bootstrap fail before listening;
this transport does not certify deployment authentication.

### Agent Orchestration

Automata should own orchestration primitives for application and add-on agents.
Opus should provide application context, registered services, and observable
results.

Acceptance criteria:

- Add-on agents can be described and activated independently.
- Central orchestration can inspect add-on readiness without taking ownership
  of add-on internals.
- Agent outputs are represented as stable command or service results.
- Agent descriptors register without constructing provider clients or runtime
  sessions.
- Runtime factories remain provider-neutral and may resolve hosted,
  self-hosted, or fallback providers from the descriptor's opaque profile
  reference.
- Central and specialist runtime instances are isolated by agent and tenant;
  specialist startup requires an explicit tenant, active add-on state, and
  current capability grant.
- Start, suspend, resume, stop, and cancel transitions are idempotent and
  persist structured state independently from process-local runtime objects.
- Each runtime operation receives the current tenant, actor, capability, and
  correlation context even when a provider adapter is reused across requests.
- Successful cancellation is persisted and deduplicated until a subsequent
  task execution begins for that agent scope.
- Every provider execution receives an immutable execution identifier, and
  cancellation targets that exact generation so an older cancellation cannot
  affect a replacement execution on another host.
- Each tenant-and-agent scope admits one active provider execution at a time;
  overlapping requests fail closed until that generation completes.
- Shared runtime state uses an injected atomic synchronization boundary. MySQL
  hosts use connection-scoped advisory locks; lifecycle and cancellation claims
  carry bounded leases so dead workers can be recovered without host-local lock
  assumptions.
- Task execution revalidates lifecycle and permission policy and returns
  provider-neutral output, diagnostics, trace, and correlation metadata.
- Opus selects permitted participants and context. Automata owns hierarchical
  and peer orchestration execution; Opus does not reimplement orchestration
  patterns.
- Delegation admits only the application coordinator and explicitly selected
  specialist or generated add-on agents. The coordinator receives participant
  identifiers instead of specialist tool maps, while each specialist executes
  through its own capability, tenant, lifecycle, and runtime boundary.
- Automata hierarchical orchestration receives normalized, provider-neutral
  worker outcomes. Specialist workers do not inherit peer results unless a
  later collaboration contract explicitly grants that exchange.

### Scoped Wise Profiles And Conversational Defaults

Every human user, central agent, and add-on specialist has a private Wise
profile namespace. Opus owns the application and tenant composition boundary;
Wise remains responsible for package-neutral resource behavior and Synthetiq
remains responsible for conversational and learning semantics.

Acceptance criteria:

- Profile identity is explicit and combines tenant, principal type, and stable
  principal identifier.
- Todos, notes, functions, calendars, goals, and steps are distinct private
  namespaces even when a current upstream adapter combines two concepts.
- Role grants apply only within the current profile by default. Cross-profile
  access requires a bounded delegation capability and never crosses tenants.
- Tenant or principal denies take precedence over role grants and narrower
  configuration cannot weaken an explicit deny.
- Command discovery removes denied profile resources without revealing their
  names, and command execution rechecks the same policy.
- Confirmation continuations remain pinned to the originating actor, tenant,
  agent, tool, and profile.
- A denied continuation exposes no stored tool, profile, command or token.
  Missing and foreign tokens produce the same public denial for the same
  caller context. Scope checks precede profile policy resolution; denial must
  neither consume the owner's continuation nor invoke the underlying processor.
- The built-in conversation catalog is versioned and deterministic, starts in
  shadow mode, and contains only safe command-discovery and private-profile
  read examples.
- Route seeds, classifier artifacts, conversational memory, and fallback
  review candidates remain separate lifecycles rather than one training
  corpus.
- Classifier cache keys are non-empty and derived from immutable dataset and
  profile scope data; derived model artifacts use scope-isolated storage keys.
- Unknown-intent candidates require review before promotion. Private
  conversations and provider payloads are excluded from default capture.
- Automatic conversational observation and generated-fallback training are
  disabled until an approved promotion contract is available.
- Loading profile or conversation defaults does not construct an inference
  provider, start training, or execute a command.

DevElation extension points preserve these boundaries:

- `opus.conversation.settings` filters the composed settings plus read-only
  application, tenant, and principal layers.
- `opus.conversation.configuration` filters the complete scoped configuration
  plus read-only profile metadata.
- Opus reapplies profile scope, catalog identity, immutable routes, classifier
  cache identity, privacy exclusions, disabled automatic observation, and
  review requirements after filters run.

### Templates And Generation

Vibrato should own Vibe parsing, validation, rendering, and generation
contracts. Opus should provide templates, variables, and safe output paths.

Acceptance criteria:

- Vibe sources can be validated before execution.
- Deterministic Vibe templates can render without backend side effects.
- Shipped themes use `.vibe` sources, canonical `{$value}` output, named
  application regions, and executable file includes.
- Theme context is escaped recursively unless a renderer call identifies a
  field as trusted markup at an explicit composition boundary.
- The application registers its Vibe renderer as the canonical `template`
  service and retains `vibe.theme` as a compatibility alias.
- Ordinary theme rendering uses BlueCore's global `template(theme, file, data)`
  facade. Rendering that carries application-owned trust policy may call the
  canonical service directly rather than extending the helper signature.
- Host frontend registration uses `common/config/markup.php` to select a
  complete host-owned Vibe theme. `OPUS_FRONTEND_THEME` selects a contained
  `resource/markup/<name>` theme by default, and `OPUS_FRONTEND_FALLBACKS` configures an
  ordered comma-separated fallback list (Ada by default). The configuration
  names required templates (`default.vibe` and `login.vibe` by default).
  Global settings define `OPUS_HOST_MARKUP_DIRECTORY` as the host-relative
  markup directory; `opus.frontend.markup_directory` can replace it at
  registration time while contained path resolution remains authoritative.
  BlueCore's caller-relative `get_template_path()` resolves PHP includes, not
  registered Vibe theme roots; host selection uses `RuntimePathResolver`.
  An unavailable replacement falls through the configured list and then to the
  package default; incomplete themes are not selected. Admin remains
  package-owned. Asset build
  selection and release rights are separate gates. Host CSS must be linked as
  an external asset rather than embedded in Vibe markup, where CSS directives
  can be parsed as template tags. Synthetic renderer checks preserve semantic
  controls and asset links, but visual accessibility of the supplied Ada assets
  still requires browser proof.
- Browser-side Reactor bindings remain distinct from server-side Vibe
  variables so initial rendering does not consume live client placeholders.
- Rendered artifacts can be written only inside the application workspace.
- Code generation entrypoints return structured success/error data that tests
  and command resources can inspect.

### Add-On Boundary

Add-ons should be package-owned and individually testable. Opus installs,
activates, deactivates, and surfaces them through BlueCore add-on contracts.

Acceptance criteria:

- Installed add-ons do not require Opus to patch their namespace or autoload
  behavior.
- Add-on readiness checks report package issues as package issues.
- Theme declarations use string-valued relative directory and entrypoint paths.
  Reject absolute paths, dot traversal, malformed metadata, missing files and
  resolved paths outside the package markup tree before reporting validity.
- Lifecycle readiness requires a boolean `ok` result. Missing or coerced success
  flags, malformed hook/batch collections, and nonempty datasource results
  without boolean outcomes are blocked with stable diagnostic codes. Empty
  datasource arrays remain compatible placeholders for stages not run.
- Batch readiness preserves completed changes and recounts failed children;
  malformed evidence is not permission to repeat side effects. An absent optional
  hook keeps its existing skipped behavior. Readiness describes the reported
  operation, not authorization, tenant isolation, or a complete runtime proof.
- Opus exposes lifecycle commands without coupling to add-on implementation
  internals.

### Frontend Presentation

Reactor should replace the legacy dashboard presentation code incrementally.
The migration should keep existing screens stable while replacing shared
binding, module, response, and transport concerns with package-owned Reactor
exports.

Acceptance criteria:

- Shared frontend bindings come from Reactor where an equivalent export exists.
- Legacy dashboard modules are migrated in focused slices with build coverage.
- Webpack remains a bundler detail, not the source of UI contracts.

## Testing Contract

Baseline tests must cover:

- runtime contract manifest and readiness behavior
- console command summaries and target listing
- optional interpreter validation without requiring the interpreter in the
  default unit suite
- command registration, failure, and output contracts for the Wise-aligned CLI
- generation validation, rendering, path boundaries, and publication behavior
- frontend build contracts once webpack cleanup begins

Optional integration tests remain opt-in and must not require secrets on a
clean checkout.

## Known Gaps

- Installed add-on packages currently emit optimized-autoload warnings, and one
  transitive authentication dependency has a reported advisory. Compatibility
  fixes are tracked in the packages that own those constraints.
- The frontend still imports legacy dashboard modules directly; the Reactor
  dependency is present but not yet wired through the application entrypoints.
- The terminal surface can use the optional Ratchet integration when the host
  dependency graph supports it. A later slice should decide whether that
  remains the websocket transport or becomes a Wise-mediated console channel.

## Implemented In This Slice

- Refreshed Composer dependencies for the current Blue Fission package graph.
- Added the current add-on packages as Composer-managed Opus add-ons.
- Added Vibrato, Wise, Presence, Synematic, and supporting Blue Fission
  repositories to the Composer graph.
- Added Reactor to the npm graph.
- Added `VibeGenerationService` as the Opus-owned generation facade.
- Updated `CodeManager` to use the Vibrato service instead of the incomplete
  scaffold factory placeholder.
- Added focused PHPUnit coverage for Vibrato validation, rendering, and bounded
  file output.
- Added runtime contract manifests, validation commands, fixtures, and focused
  readiness coverage.
- Updated the PHPUnit configuration to the supported PHPUnit 9.6 schema.

## Release Acceptance

### Delegation failure privacy (#29)

Unexpected orchestration exceptions return a failed delegation result with the
stable `agent_delegation_operation_failed` diagnostic and the host tenant and
correlation identifiers. Exception messages and partial orchestration traces are
not returned. A failure after completed work does not imply rollback and must
not automatically replay agent effects. An explicit later request can recover.
This boundary does not sanitize declared runtime outputs or diagnostics.

- Roadmap items have issue-sized slices with clear acceptance criteria.
- Runtime contract proof tests pass in the default unit suite.
- Backend CLI work remains organized around Wise kernel compatibility.
- Generation operations return structured results and preserve workspace
  boundaries.
- Webpack and asset-pipeline cleanup remain separately tracked.
- Specifications and tests stay synchronized as each slice lands.

## Baseline quality gates

Every tracked PHP file must compile under PHP 8.2, including files outside test
autoload paths. CI runs this dependency-free check, the locked PHPUnit suite,
and frontend asset-manifest/configuration checks without service credentials.
Dynamic processing dispatches through the shared command backbone with host-owned
context. All rule shapes and input bindings are validated before dispatch;
pending, rejected, or failed commands stop the sequence. Runtime effects already
completed are not rolled back. Rules cannot supply authority or execute arbitrary
PHP functions or direct API calls.

## Command-context lifecycle authority

The command-context filter may enrich metadata but cannot replace the activated
add-on list or lifecycle state map resolved by the host query. The host restores
both fields after filtering, including when the lifecycle query is unavailable.
Continuation refresh must reread current activation and cannot resurrect a
revoked add-on from a hook or prior context. Capability-map resolution must deny
inactive add-on tools while retaining access to genuinely active add-on tools.

Lifecycle reads are atomic for authority: if fetching or normalizing any record
throws, both the activated add-on list and lifecycle state map are empty.
Previously parsed records, prior continuation state, and context filters cannot
restore partial authority after that failure. Actor and tenant scope remain intact.

## Optional consulting guidance (#101)

An injected advisory provider accepts a versioned, inert request and returns a
versioned inspectable outcome. The host verifies request/scope identity, candidate
references, expiry, evidence freshness and required approval metadata. Guidance
never grants execution authority. Unavailable/failed providers return structured
results without exception payloads. Caller overrides create new advisory records;
no task, budget, goal, agent, command, deployment or workflow is mutated.

The registered guidance service resolves the configured provider binding and
participates in DevElation's native service parent and behavior routing. A host
may register an advisory action that calls the same validated `advise()` method;
such routing does not grant execution authority or make direct method calls
automatically observable. The default provider remains unavailable.

## Canonical add-on boot registration (#40)

The platform and selected application profiles consume canonical registration
factories through the engine's `OnAppLoaded` behavior. Loading installation hooks
does not execute the factory. Registration rechecks the active stored identity,
validates the package before executing it, loads the declared registration class,
and invokes its contributions once per engine. Declared themes register under the
package name only after the provider succeeds. Returned data catalogs do not grant
agent, command, or menu authority.

Repeated load and dispatch, including reentrant dispatch, do not repeat effects.
Deactivation before dispatch skips the package. Unavailable or invalid canonical
packages fail boot before execution; provider failure leaves a terminal failure
for that engine and cannot replay partial effects. Failure messages exclude
package exception payloads. Restart is required to retry or apply activation
changes after boot. Historical imperative entrypoints retain the legacy loader
contract and must migrate before receiving these guarantees.

## Exceptional command outcomes (#13)

CommandHost converts unexpected processor and presentation exceptions into bounded
failed presentations with exit code 1, a stable stage code, unknown effects, and no
partial output, provider metadata or continuation token. It invokes the processor
once and never retries after a failure. A private host-origin marker distinguishes
these failures from ordinary provider-declared FAILED results and cannot be set by
provider metadata. CLI and programmatic calls share the same result. Terminal
sessions retain their existing close-and-revoke behavior on host failures, including
failed approval continuations. Defined provider outcomes retain their existing
status, output, permissions and approval semantics.

## Agent execution persistence failure (#125)

An execution request whose host state cannot be read must fail closed before
runtime construction or execution. If execution has started, failure to record its
completion must retain the host execution and correlation identifiers, report an
unknown lifecycle state and effects, and require reconciliation. It must not retry
execution or erase an unresolved execution claim as an exception-cleanup shortcut.
Runtime exceptions retain their bounded diagnostic even if claim cleanup also
fails. Failure records never copy exception text or task/provider payloads.


### Resumable intake persistence integrity (#95)

All four structured intake columns (answers, defaults, skipped fields, actor)
must serialize successfully before any storage mutation. Invalid encoding must
return a stable failure without exposing content or replacing a saved revision.
Stored structured columns must decode to arrays; invalid JSON, scalar roots and
non-string storage values fail explicitly rather than silently substituting empty
data. Valid Unicode and normalized nested values retain round-trip behavior.
This boundary does not certify live database deployment or add command, provider,
planning, code-generation or execution authority.

### Add-on organization persistence (#120)

The organization service owns application/tenant-scoped feature groups, ordered
many-to-many memberships, and per-add-on tags. Groups and tags are descriptive;
they never supply package dependencies, capabilities, roles, or lifecycle grants.
An injected host policy must explicitly authorize every read and mutation, including
bulk members. Missing actor or any denied target rejects the whole mutation.
Group visibility is evaluated by that policy, not inferred from caller roles.

A revision-checked store commits organization state and its audit receipt in one
transaction. Stale writers cannot replace newer organization metadata; failed
audit persistence rolls back the mutation. Empty installations remain ungrouped.
The first storage adapter uses an explicitly configured private SQLiteLink and
an opt-in BlueCore delta; constructors never create tables.

Proof includes real database reopen, isolated application/tenant scopes, denied
bulk writes, hidden-group non-disclosure, stale writers, audit failure rollback,
deterministic ordering, 100-add-on filtering, and tags that do not change dependency
metadata. HTTP/Wise/UI binding and authoritative lifecycle impact enforcement are
subsequent integration work and are not claimed by this persistence increment.



### Agent runtime result observation (#110)

Publish `opus.agent.runtime.result` after a public composition operation returns
its correlated result. Only bounded operation/status values and an outcome
boolean enter the versioned payload; identities, task/output data, diagnostics,
state, correlation tokens and runtime metadata remain private. Observer return
values and exceptions cannot change the result or replay the operation. Nested
composition operations and descriptor registration during synchronous delivery
are denied before inspecting state or constructing a runtime. This action is
best-effort observation, not authority, durable audit or proof of remote effects.

### Principal advisory module admission (#29)

An opt-in principal runtime factory must admit only application-owned central
descriptors with explicit application scope and configured profiles. Readiness
and lifecycle startup must not construct an Agent or cognitive module. Each
evaluation requires fresh typed authorization bound to the host subject and
capability before lazy construction and real Automata `Agent::runModule` use.
Task data cannot select factories, profiles or identity. Advisory modules receive
isolated state; proposed writes, decisions or state mutation are rejected before
they reach the retained Agent. Outputs remain advice requiring review, never
execution authority. Revocation, foreign scope, disablement, reentry and retirement
must deny evaluation. Existing strategy routing remains independently available;
this optional runtime does not activate an inference provider or install modules.

### Shared runtime administration commands (#125)

Runtime inspection and explicit lifecycle/cleanup operations must use the same
Wise parser and CommandHost as other commands. Text and structured input must
produce equivalent authorized operations. Host-resolved current identity,
application, tenant and per-operation grants must precede runtime inspection or
construction; command payload/context cannot mint those grants. Parsing is
passive. Unsupported verbs, arguments and replay/continuation attempts must not
fall through to a second runtime executor. Existing non-runtime commands retain
their processor behavior. This command boundary does not install an HTTP
authentication policy or implement retries, dead-letter replay or provider health.

### Scoped runtime unregistration (#29)

Stale owners must be able to retire only their retained local runtime through
an explicit idempotent instance-local contract. Fresh application, tenant,
capability and add-on authorization still applies. Retirement must not construct
a runtime, admit work, or alter the newer persisted registration. Unsupported,
failed or uncertain cleanup retains the handle. Successful retirement permanently
fences the old object and evicts only that local cache entry. Reentrant cleanup
is denied; distributed drain and remote shutdown remain host responsibilities.

Descriptor discovery and runtime enrollment are separate. An authorized operator
may unregister one application/tenant/agent runtime after its stop operation
succeeds. Persist an unregistered tombstone and evict only that local cache entry;
other tenant runtimes remain registered. Start must require explicit runtime
registration after this tombstone, including after host reconstruction. Repeated
unregistration is idempotent. Failed cleanup retains the runtime for retry.
Registration is metadata-only and must not instantiate or start a provider.
Descriptor replacement must not silently discard a cached runtime; drain and
unregister its local scopes first. This is host runtime enrollment, not removal
of package descriptors or proof of distributed-process/module teardown.

### Explicit application runtime scope (#29)

An installation may bind its runtime state store to an immutable application
identity supplied by trusted host configuration. Explicitly scoped composition
requires the same application identity in every lifecycle/execution context,
before state reads, provider discovery or runtime construction. An absent
identity is not an application-wide grant. Tenant and agent checks still apply.
Shared storage and operation locks must isolate identical tenant/agent names
across applications, and native strategy subjects must include the application.
Legacy unbound composition may accept only unbound contexts; it cannot claim
these guarantees. Scoped storage never inherits legacy claims or approvals.
This boundary does not install Cogito modules or provide their teardown.

### Conversational learning policy guards (#99)

Unknown-intent capture requires a strict boolean allow through the package,
application, tenant, and principal layers. An explicit denial or malformed
explicit value cannot be reversed by a narrower layer or either conversation
configuration filter. A filter may disable capture but cannot grant denied capture.
Scoped fallback candidate hints must reflect the effective capture decision without
mutating package seeds. Trusted-artifact requirements, disabled serialized learning
state, scope isolation, review and persistence requirements remain mandatory after
configuration extensions. These are host configuration guarantees; persistent
learning, application-scoped profile adapters, live authorization and promotion
workflows remain required before #99 is complete.

### Authoritative Wise resource mappings (#61)

Wise delegate names, classes, aliases and unavailable-capability diagnostics come
from the released ResourceCatalog in the locked optional Wise package. The host
preflights the catalog and every available definition before any Wise delegate or
command mapping is written. Missing required integration fails; missing optional
integration skips the entire Wise mapping. Local application commands remain
independent. Catalog lookup must not construct resources or providers, and catalog
availability is not a permission grant or proof that private profile adapters exist.
Host-owned command verbs remain explicit; aliases inherit their canonical bindings.

### Add-on PSR-4 declaration alignment (#43)

Every named class, interface, trait and enum in `logic/` must match its exact
case-sensitive PSR-4 namespace and filename. Validation inspects PHP tokens;
it must not load a class, invoke an autoloader or execute package statements.
Anonymous classes, class-name constants and function-only lifecycle files do
not introduce named autoload declarations. Validation reports the offending
relative path with `class_file_mismatch`. This source contract is not a sandbox
or a claim that arbitrary package code is safe to execute.

### Package and embedded validation profiles (#43)

Package validation requires local Composer and test tooling. Explicit embedded
validation may inherit missing tooling from a containing host whose Composer
autoload mapping points to the package's logic directory. It must never execute
tooling, silently replace malformed local files, infer the host from ambient
state, or let package metadata select a weaker validation profile. Both profiles
retain definition, mapping, namespace, registration and Vibe checks. Required
domain directories follow the declared `layers`; unused layers need no empty
directories. Legacy omissions and inherited tooling produce stable warnings,
while missing declared layers and invalid contracts remain errors. Engine boot
uses the host-selected profile captured when registration is queued.

### Public private-asset boundary (#68)

Reserved Snow and private-assets path components must be excluded from normal
staging and public source/package archives. Forced staging must fail a dependency-
free CI audit with redacted structured reasons. Synthetic fixtures must retain
public fallback templates while excluding private sentinels. Private injection
and container-layer proof remain separate deployment acceptance requirements.

### Optional add-on boot isolation (#125)

Host composition may mark exact canonical package names optional before queueing.
That policy is captured with the package identity, cannot be supplied by package
metadata, and does not alter activation or authorization. Required failures stop
boot. Optional failures may continue only while no package code has run; the
failed diagnostic remains inspectable with required/isolated flags. Partial or
unknown effects still stop boot. Later policy changes and file repair must not
replay failed registration inside the same engine. Real Engine bootstrap proof
must preserve an unaffected host route and separately registered healthy package.

### Canonical boot result observation (#110)

Publish one detached, redacted boot report through opus.addon.boot.result after
a canonical queued registration settles, preserving the original outcome and
failure. Observer return values, argument mutation, exceptions and reentrant
boot events must not change policy or replay registration. Disabled dispatch and
later subscriptions do not backfill results. Catalog version1.3.0 documents the
payload, order and failure policy; lifecycle mutation stays service-owned.

### Passive DevElation source inventory (#151)

Provide a dependency-free audit command for app/common/mapping PHP source with
stable per-module summaries, source hashes and line-addressed primitive, service,
behavior and hook references. Keep declarations, static fluent chains and
unresolved behavior candidates distinct. Never execute inspected source or infer
runtime compatibility from lexical counts. Missing/unreadable or unparsable input
must yield an incomplete report and a nonzero process result.
# Command result observation

The shared CommandHost publishes one observe-only DevElation action after a
presentation or a private-safe host failure settles. Its versioned scalar
summary contains a normalized status, confirmation flag, host-failure marker
and phase, never input, output, metadata, tenant identity or continuation proof.
Observers cannot replace the result or replay it through a nested CommandHost
call. Observer exceptions do not convert success to failure or retry execution.
The action is synchronous, best-effort and requires DevElation to be enabled;
it is not durable audit storage, a permission grant or a sandbox for PHP code.

# Intake observation privacy

The persisted intake transition action must publish only a versioned transition
and status summary. Answers, defaults, actor and tenant identity, correlation
tokens, timestamps and application names must not enter the global observation
payload. Nested intake mutations during observation must fail before repository
access, across service instances. The existing post-commit exception contract
remains unchanged; guard state must reset even when an observer throws.

# Host-owned command tenant scope

Command-context filters cannot select a tenant when the host provided no tenant.
Null is an authoritative scope, not permission to derive membership. Discard
scope-changing filter output before profile resolution on both initial commands
and continuation refreshes. Resolve and authorize tenant membership at the host
boundary before constructing command context; preserve safe metadata enrichment
within an unchanged scope.

# Explicit delegation selection

A coordinator's explicit empty worker list selects no specialists. A malformed
or unknown worker selection must fail before any specialist executes, rather
than silently widening or partially accepting the plan. An omitted workers field
retains the existing host-participant default; valid duplicate IDs execute once
in requested order. Use the installed Automata orchestration implementation and
preserve host authorization on every actual runtime execution.

# Delegation evidence

Delegation must retain provider-neutral per-execution usage and trace evidence
from the principal and each executed specialist. Execution identity and request
scope remain host-owned. Missing or invalid measurements stay unknown, rather
than becoming zero; no monetary or energy total is inferred across unspecified
units. Successful execution does not imply confidence. A supplied finite score
in [0, 1] is retained; otherwise confidence is null. Aggregate confidence is
unknown if any executed participant has unknown confidence. Evidence must not
change selection, authorization, retry or execution count. The added usage,
trace and request-scope fields must stay outside global observation payloads.
See `docs/delegation-evidence.md`.

# Lazy command runtime initialization isolation

Lazy command factories and their ready/unavailable observers must not construct
or execute nested command runtimes through this host adapter, including
already-cached other instances.
Nested calls return a stable non-observing denial. The preparation fence always
releases after failure or success; an explicit later request can recover from a
failed build, and normal command execution still delegates once. Construction
failure must remain distinct from execution failure. Reuse Wise result contracts
and DevElation notifications; do not introduce automatic retries or a second
runtime engine. Wise remains an installed runtime requirement.
