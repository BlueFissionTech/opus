# Native workflow composition

WorkflowService extends DevElation Service and is injected with a connector and
a trusted approval resolver. WorkflowCommandProcessor decorates Wise's processor;
it receives authenticated context from the host, never command arguments.
Definition and request records are immutable at the authority boundary.

Synematic Pipeline sequences mapping and invocation. Its values are fixed stage
labels, with empty context; payloads, identities and approval stay in closures.
Retries are disabled. Explicit retries are accepted only after a connector has
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
