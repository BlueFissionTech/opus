# Keryx — scoped native reads

Implements the bounded directory, message-count and capability projection of
Opus #85. It is not a hosted control plane or a complete migration of operations.
See SPEC.md and ARCHITECTURE.md for the audit and remaining native API gates.

## Trusted host integration

Construct `Gateway` with an approved origin and `ReadService` with the current
application/tenant, a map of public aliases to pinned canonical repository ids
and names, and a closure returning a freshly authenticated `AgentRuntimeContext`.
Grant `keryx.read` only to authorized actors with the add-on active. Never build
these bindings from model-supplied input. Register explicitly on the same native
Application used by Wise: `$service->registerWith($application)`.

Wise then handles `get keryx "directory" "primary"`, `get keryx "messages"
"primary"`, and `get keryx "capabilities" "primary"`. Registration does not
connect. Native Wise delivery completion is not evidence of a verified read:
inspect the JSON result's `status` and `reason`.

Default transport uses SimpleClients' native Cloud HttpClient and DevElation
Curl: five-second timeout, two-second connection timeout, one-MiB body limit,
TLS verification and no redirects/retries. HTTPS is required unless the trusted
host explicitly opts into development HTTP on a controlled private network.
No credentials are read from environment or stored by the package. A host may
inject a reviewed native HttpClient; it remains responsible for equivalent
transport bounds and authentication. Do not expose this development integration
as a remote multi-tenant service without the separate security review.

All directory responses are partial and provide no next-page token. Missing
identity is unverified, never proof of deletion. Job and discussion operations
are unavailable without sending a request; audited GET implementations have
side effects. No send, acknowledgment, worker, shell or deployment tools exist.

## Offline validation

From the Opus host with the approved dependencies already installed:

```sh
php vendor/bin/phpunit -c addons/keryx/phpunit.xml --do-not-cache-result
php bin/opus-addon.php validate addons/keryx package
```

Authority and response-shape tests inject native HttpClient fixtures. Transport
tests use a disposable loopback HTTP server and the default native client; they
cover status/body fidelity, no redirects/retries, body limits, interrupted
responses, timeouts and connection failure. They require PHP curl and skip when
it is absent. No real daemon or provider is contacted and no credentials are
needed. The fixture-only `KERYX_FIXTURE_MODE` and `KERYX_FIXTURE_REQUESTS` variables
are set only in the spawned fixture process, not in host configuration. Both
contracts also run in the ordinary Opus host suite. Passing them does not qualify
a deployed gateway, TLS endpoint or remote authentication boundary.

Keryx is an Opus add-on.

Opus queues the factory in `main.php` for the engine's `OnAppLoaded` behavior.
Keep the factory passive: installation may load it while the add-on is inactive.
Put boot contributions in `AddOnRegistration::contributions()`, not another lifecycle listener.
Use `BlueFission\Services\Mapping::add()` in API/app/default mappings for routes;
leave agent, menu, and console catalogs under their owning consumers' permissions.
Opus registers declared themes as `keryx/<theme>` after contributions succeed.
Repeated dispatch does not replay registration, even after failure. Restart the application
after repairing a failed boot; partial PHP effects cannot be rolled back.
