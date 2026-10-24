# Native capability audit and boundaries

Audit before implementation, 2026-10-23. Dependency references are from the host
lock; discovery of newer source does not authorize adoption.

| Responsibility | Native owner / exact reference | Composition and limit |
| --- | --- | --- |
| HTTP | SimpleClients `9321728041b016e1e80eca6fcd4dff9e24e720ce`, `Cloud/HttpClient.php`, `tests/CloudHttpClientTest.php` | Reuse observed HTTP status, request and close behavior. Do not use `HttpJsonContractClient`, which synthesizes status 200 even for an empty fetch. |
| Connection bounds | DevElation `4b9079b8b34010ddd5e0f92774ae9abafbe9ea77`, `Connections/Curl.php` | Configure native timeouts, TLS, no redirects and a bounded write callback; no parallel HTTP engine. |
| Commands | Wise `ab4975fd85f8dff9d3f79337b4a3c6fab2e7f855`, `Cmd/CommandProcessor.php`; DevElation `Services/Application.php` | Native parser dispatches registered `keryx.get` to a Service. No copied parser/processor, global resource registration or BaseResource file-store lifecycle. Host explicitly installs the binding with fresh authority. |
| Directory | Keryx `ed3a72a9e6ff220a5e82394cd8b28877122113fc`, `KeryxDaemon.php`, `RepoRegistryService::listDirectory` | Canonical id/name pair must match host binding. Limit/filter are not pagination or proof of completeness. Paths/aliases/descriptions are discarded. |
| Message summary | Same Keryx reference, `MessageService::summary` | Global aggregate is projected to the authorized canonical repository; only severity counts survive. No bodies or acknowledgments. |
| Catalog | Same Keryx reference, `buildCapabilityRegistry` | Check native endpoint declarations; return only the fixed admitted tool set. Mixed read/write capability groups never grant mutation authority. |
| Job/discussion status | Same reference, job route and `DiscussionService::summaryForRepo` | Job GET dispatches workers in queue mode; discussion summary calls `applyLifecyclePolicies`. Neither is admitted as a read. Native non-mutating snapshots are required. |
| Authority | Opus AgentRuntimeContext | Host owns authenticated context, tenant/application binding, capability grants and lifecycle. A pinned endpoint or repository name is not authorization. |

The narrow adapter only constrains and projects existing native read responses.
Replace it with a reviewed native Keryx client when equivalent status, bounds,
identity, redaction and non-mutating semantics are released and adopted. Annex
compatibility is descriptive metadata only; no Annex SDK is installed or claimed.
No daemon persistence, subprocess runner, scheduler, queue or workflow is copied.

The gateway is trusted host configuration, never model input. Remote tenancy and
authentication are not qualified; an untrusted host/plugin can bypass in-process
policy. This is not an isolation sandbox. Wise's outer completed status denotes
command delivery; callers must inspect the structured read result's `status`.

`GatewayTransportTest` exercises the installed native Cloud HttpClient/Curl
combination over a disposable loopback server. The default transport retains
actual status and body bytes, does not follow redirects, rejects interrupted and
oversized responses, and times out without retrying. `ReadServiceTest` separately
qualifies native Application resolution, delegation and Wise dispatch, including
authority revocation. Both are included in ordinary host CI. This does not prove
remote TLS configuration, daemon authorization, persistence or cloud readiness.
