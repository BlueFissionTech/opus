# Kwikle

Kwikle's first executable package composes a fixture trigger and capture action
with Synematic GraphDocument, Graph and Hub. It uses Opus's Wise command backbone and DevElation
Service delegation. No network provider, credentials, database or background
worker is required for the fixture.

Commands use Wise's quoted argument syntax:

    get kwikle-workflow "list"
    get kwikle-workflow "validate" "capture"
    get kwikle-workflow "explain" "capture"
    get kwikle-workflow "dry-run" "capture" "event-1" "subject"
    do kwikle-workflow "run" "capture" "event-1" "subject"
    get kwikle-workflow "status" "capture" "event-1"
    do kwikle-workflow "retry" "capture" "event-1" "subject" "2"

The host binds reviewed Workflow definitions, a FixtureConnector, a review
callback and a fresh authenticated context resolver (`context`) through
Application::bindArgs for WorkflowService, then resolves and
delegates that service. Wrap the host's Wise processor with
WorkflowCommandProcessor and inject a fresh authenticated AgentRuntimeContext
resolver. The host can register an Action on the delegated service to invoke the
processor. WorkflowServiceTest demonstrates actual resolve/delegate/register/
perform calls, including revoked execution permission on the next invocation.

For direct construction, pass the trusted resolver as the fourth argument:
`new WorkflowService($workflows, $connector, $review, $resolveContext)`.
It must read current host authority for the same request, not return a cached
grant or derive authority from command arguments. Wire that resolver into the
command processor as well. Omitting it keeps discovery available but execution
denied; there is no fallback from a missing resolver to the caller's snapshot.

The passive package factory declares its console resources and specialist agent;
it does not invent a tenant, automatically approve work, or replace the host
processor. Installation alone does not activate execution. Agents require the
same host capabilities and review callback as human callers.

The trusted review callback returns Approval containing the exact immutable
RunRequest instance, application, tenant, actor, and an explicit compensation
decision. It must return no approval until human/policy review is complete.
Command arguments and returned provider data cannot supply approval. Both read
and execution check active add-on state and host capability grants.

Execution rechecks host authority after review and connector availability, then
immediately before capture and separately approved compensation. Application,
tenant, actor and correlation must remain unchanged. Resolver failures, revoked
capabilities and changed lifecycle state deny new effects. Native graph callbacks
cannot retain old grants. A denial during graph execution is a settled receipt,
not a retryable failure. Revocation after a confirmed effect does not erase it:
successful capture stays committed; partial capture stays partial if compensation
is no longer authorized. Host resolution, review and native callbacks cannot
reenter workflow execution across service instances.

Receipts are bounded to 1,000 events per service instance. Duplicate events for
one application/tenant/workflow cannot execute under a second actor. Stored
payload conflicts reject. Only a failed attempt with confirmed no effects may
retry, within the configured maximum of three; uncertain outcomes cannot replay.
Compensation is only attempted for confirmed partial effects with its explicit
approval. Receipts and fixture captures disappear when the process ends.

Pass a native `BlueFission\Synematic\Orchestration\GraphDocument` as the optional
`graph` argument of `Workflow` to configure the reviewed fixture topology.
Synematic owns schema validation, resolver references, edges, conditions and
bounded traversal. The fixture admits `fixture.created`, `fixture.subject` and
one `fixture.capture` node, plus the `fixture.subject-present` condition. It
admits first-match graphs with at most 16 steps/nodes and 32 edges. Unsupported
resolver references or modes are unavailable before approval or effects.
No callable names, provider URLs or command text are executed from the document.
Only fixed stage labels enter the graph; subject data remains private to the
approved handlers. A condition that ends the graph before capture reports
`skipped`. Tests demonstrate native branching and immutable definition snapshots.

The kwikle.run.result hook emits only version, status and effects. Private input
and identities are excluded from Synematic values/context and observer events.
Observers cannot change a settled result or reenter execution. Run results carry
the host correlation ID; they omit connector output and exception details.

Run within a compatible Opus source checkout:

    php vendor/phpunit/phpunit/phpunit -c addons/kwikle/phpunit.xml --do-not-cache-result
    php bin/opus-addon.php validate addons/kwikle

See PRD.md and ARCHITECTURE.md for the durable execution and upstream provider
contracts still required. Fixture capability references are local labels, not
a claim of Annex conformance. SimpleClients adapters and Annex manifests have not
been activated. The specialist descriptor does not grant its own permissions.
