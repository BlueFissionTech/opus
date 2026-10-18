# HTTP authentication boundary

Both `auth` and `admin:auth` mappings use the Opus authentication gateway.
The gateway permits dispatch only when the configured authenticator reports
an authenticated identity **and** successfully establishes the session. Request
arguments cannot replace either result.

Authenticator resolution is lazy and occurs inside the denial boundary. Hosts
may inject a trusted resolver callable; a missing, invalid or failing provider
denies access. Constructing the gateway does not initialize identity storage.

A denial throws `AuthenticationRequired` before the application prepares its
controller operation. The public entry point catches that specific exception,
returns HTTP 401 with `Cache-Control: no-store`, and emits:

```json
{"ok":false,"error":"authentication_required"}
```

Provider resolution, session read/write exceptions and failed cleanup return the same public reason;
provider exception text is not attached to the exception or response. Cleanup
is best effort and cannot authorize execution. Unrelated application exceptions
retain their existing handling.

## Compatibility

Protected endpoints no longer use a login redirect as their denial response.
API and administration-panel clients should handle 401 and invite the user to
sign in through the existing login interface (`/admin` for administration).
Successful authenticated requests retain their existing route behavior.

Embedded hosts that provide their own HTTP entry point must catch
`App\Domain\User\AuthenticationRequired` at the request boundary and stop
dispatch. They can use `AuthenticationFailureResponse::send()` for the same
response contract. Do not catch the exception and then resume `run()`.

This boundary validates authentication, not administrator status. The name
`admin:auth` does not grant a role. Native role/grant/deny management, current
membership and tenant checks, cross-surface policy, revocation, and privileged
operation confirmation remain work under #123. No grant is inferred from a
username, display name, initial account identifier, request payload, or model
output. New privileged organization operations must wait for that policy.

## Proof

`AuthenticationGatewayTest` exercises the actual DevElation application
`process()->run()` route path with controlled authentication/session outcomes.
Denied requests cannot execute the route effect; successful requests still do.
Tests cover both configured gateways, unsuccessful session establishment,
identity and session exceptions, cleanup exceptions, and the HTTP response.
The test is source-level host composition proof, not a production identity,
database, tenant, or complete administration certification.

Run the focused contract and the full locked suite:

```sh
php vendor/phpunit/phpunit/phpunit --filter AuthenticationGatewayTest
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
php bin/lint-php.php
```
