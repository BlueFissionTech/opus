# Governed Keryx reads

Related: Opus #85. This executable slice does not complete the issue or qualify
a remotely hosted control plane. The independent Keryx daemon remains the owner
of identity, persistence, queues and operations.

## Stories and acceptance

- An authenticated host operator can resolve a configured repository alias and
  inspect its message counts without exposing paths, message bodies or secrets.
- A specialist can discover only the admitted read tools, not privileged daemon
  commands. Discovery and package loading perform no network requests.
- Every call rechecks application, tenant, actor, capability and active add-on
  authority, including after transport returns. Unknown aliases fail closed.
- Non-200, empty, malformed, mismatched or stale evidence cannot report success.
- Directory results are explicitly partial: the current native endpoint offers
  no trustworthy pagination/completeness contract. Nonzero pages are unavailable.
- Job and discussion reads are unavailable without contacting their endpoints:
  the audited native implementations dispatch workers or apply lifecycle policy.
- A loopback-only synthetic HTTP fixture must exercise the default native
  SimpleClients/DevElation transport, not an injected client: preserve observed
  statuses and body bytes, never follow redirects or retry, and fail closed on
  oversized, interrupted or timed-out responses. No real daemon is contacted.

Offline tests must exercise native application registration and Wise dispatch,
not only direct construction. No live providers, dependency installation or
daemon mutation are required. Remote authentication/tenancy, non-mutating job and
discussion snapshots, durable recovery and deployment remain separate gates.
