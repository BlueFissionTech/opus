# Native workflow composition

## Materia audit and ownership (locked source)

Audited Synematic `daeab426a8d3065d4b187bf2e29ed97f1e38e46f`,
DevElation `4b9079b8b34010ddd5e0f92774ae9abafbe9ea77`, and Wise
`ab4975fd85f8dff9d3f79337b4a3c6fab2e7f855` from the host lock. The installed
Synematic Definition, GraphDocument, Hub, Graph and Node source blobs match
that exact Synematic commit; no dependency update is needed for these APIs.

| Responsibility | Native evidence and reuse | Host boundary / limits |
| --- | --- | --- |
| Portable workflow schema, topology, resolver references | `src/Synematic/Orchestration/GraphDocument.php`: `assertValid`, `toArray`, `toGraph`; `tests/Orchestration/GraphDocumentTest.php` | Keep an immutable approved snapshot; references do not confer permission. Do not add a second graph format or validator. |
| In-memory definitions and registry | `Orchestration/Definition.php`, `Interop/Hub.php`; `tests/Orchestration/DefinitionTest.php`, `examples/definition-json.php` | Definition supports runtime handlers and is not the portable authority boundary. Register the GraphDocument-compiled Graph with Hub rather than translating through a second definition. |
| Execution and branching | `Workflow/Graph.php`, `Workflow/Node.php`; `tests/Workflow/GraphTest.php`, `examples/zapier-like.php` | Use native traversal and bounded steps. The locked Graph selects the first candidate even in `all` mode: do not advertise fan-out. |
| Linear steps and retries | `Workflow/Pipeline.php`, `tests/Workflow/PipelineTest.php` | Replace the two-step wrapper, not the upstream engine. No implicit retry of effects; explicit host-approved attempts remain separately fenced. |
| Interop, events and observation | `Interop/Hub.php`, `Audit/Trail.php`, `examples/zapier-like.php` | Native Node/Trail retain input/output/context. Pass only fixed stage labels; payload and authority remain in private closures. Hub signing is not tenant authorization. |
| Admission and command integration | DevElation `Services/Application.php` and `Services/Service.php`; Wise CommandProcessor | Existing resolve/delegate/register/perform test plus fresh-context command test must continue to pass. No discovery-time execution. |
| Durable claims, provider effects, recovery | Not established by the in-memory graph, queue or trail | Remain disabled until native atomic storage, receiver idempotency, approved clients and deployment conformance exist. Do not recreate those engines in Kwikle. |

The executable revision consumes native GraphDocument/Graph/Hub and keeps the
host-owned tenant envelope, effect admission and redacted receipts. The current
fixture admits only one capture action and reviewed fixture resolver references;
this is not a claim that arbitrary integration graphs are safe or available.

WorkflowService extends DevElation Service and is injected with a connector and
a trusted approval resolver. WorkflowCommandProcessor decorates Wise's processor;
it receives authenticated context from the host, never command arguments.
Definition and request records are immutable at the authority boundary.

The host supplies a separate live context resolver to WorkflowService. A
RunRequest-bound approval is not a lease on old capabilities: the service pins
application, tenant, actor and correlation, re-resolves after review/availability,
and checks the same scope immediately before capture or compensation. Missing or
throwing resolution denies effects. This composes existing AgentRuntimeContext
and host lifecycle/capability policy; it does not add a membership or identity
engine. The reentrancy guard covers context resolution and native graph filters
as well as review and receipt observers. Once an effect is confirmed, subsequent
revocation preserves its truthful receipt and cannot authorize a compensating
effect. Read commands still obtain fresh host context through the processor.

Synematic GraphDocument owns portable schema validation and graph compilation;
Hub registers and runs the resulting native Graph. Native nodes and conditions
are resolved from a fixture-only allowlist after host admission, never from
executable strings or callable values in a document. Trigger/action descriptors
are host capability metadata, not a second workflow engine. A default native
document preserves the initial trigger/map/capture behavior; callers can supply
a reviewed GraphDocument using the same native schema. The host snapshots its
validated data and returns fresh objects, not shared mutable authority.

Graph values are fixed stage labels, with empty context; payloads, identities
and approval stay in closures because native Node/Trail retain input and output.
Native traversal controls ordering, conditions and step bounds; an effect guard
prevents topology changes or repeated visits from multiplying the approved
capture. Normal graph completion without capture reports `skipped`, not
successful delivery. Only first-match mode, bounded graphs and one capture are
admitted by this fixture adapter, not by the eventual integration service.
No automatic retries occur. Explicit retries are accepted only after a connector has
confirmed no effects; exceptions are uncertain and cannot replay. Compensation
is a separate approval on the exact request, not a generic exception handler.

The service owns a bounded in-process receipt collection. It does not advertise
durable or multi-worker idempotency. No database queries or SQL are introduced.
A future durable repository must use BlueCore models/Scaffold and the native data
layer, with atomic claims established before enabling external side effects.

Connections contain opaque client and Annex capability references, not secrets,
URLs or request templates. Only the fixture provider is executable in this
increment. Provider adapters must consume upstream clients rather than adding
HTTP implementations here. Observer hooks expose status only and cannot authorize
or replay a run. Service public calls still require host authorization.
