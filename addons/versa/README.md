# Versa

Versa provides an offline-qualified **injected text-submission boundary**, not a
live messaging service. It reuses native SimpleClients contracts, DevElation
services and immutable objects, and Wise command parsing. No provider client,
credentials, database or connection is created by this package.

## Explicit host composition

Bind `MessagingService` constructor arguments through the native Application's
`bindArgs()` and `resolve()`: trusted `Channel` values, a current
`AgentRuntimeContext` resolver, a `Channel -> ClientInterface|null` resolver, and
a review callback returning `SendApproval|null`. Approval must reference the exact
immutable `SendRequest` and current application, tenant, actor and correlation.
Do not use the auto-approving callbacks in synthetic tests for live operation.

The host must grant `versa.read`, `versa.send`, and separately `versa.notify` as
appropriate. The add-on must be active and the actor must be a configured channel
participant. Notification also requires channel opt-in. Catalog declarations are
not permission grants. The host may explicitly delegate the resolved service and
register behaviors with native `Application::delegate/register/perform`; the
offline test executes that path. No parallel container or router is introduced.

Compose `MessagingCommandProcessor` around the host's native Wise processor and
the resolved service. Supported commands are:

```text
get versa-message "channels"
get versa-message "status" "room" "request-1"
send versa-message "text" "room" "request-1" "Hello"
send versa-message "notify" "room" "notice-1" "Reminder"
```

Native parse-only requests never call host admission, review or transport. Native
continuations and unrelated commands stay with the supplied processor. Command
context cannot supply authority or approval. Executed Versa results omit command
text and provider details. Parse-only output intentionally describes the caller's
own input; hosts must not publish it as an authorized conversation summary.

The typed API additionally accepts up to four media references (id, MIME, byte
count), not URLs or attachment bytes. Each declared attachment is at most 5 MiB;
text is at most 8192 bytes of valid UTF-8. No media is fetched or verified.

## Attempt results and limits

`submitted` means only that the injected client returned `ok()`. Delivery and
external effects remain **unknown**, even for HTTP success. Errors, negative
responses, and timeouts after dispatch are `uncertain`; authority loss suppresses
the receipt without claiming no effects. Private transport details are never
returned. Attempt status is local lookup, not a provider status query.

The same instance remembers up to 1000 scoped attempts. An identical request ID
and payload reuses its receipt, changed content conflicts, and another actor
cannot read or replay it. No automatic retry follows an ambiguous attempt.
Pre-dispatch unavailability is retryable. A new instance loses these receipts;
there is **no restart, cross-worker or exactly-once guarantee**. Do not change
request IDs or replace instances to retry an uncertain external effect.

The injected binding receives native `ClientRequest` POST `messages` with body
`channel`, `text`, `media`, `notification`, `idempotency_key`, `correlation_id`, and
empty headers/query/options. This is a package-owned binding shape, not a public
provider endpoint. The trusted resolver must select only an adapter that explicitly
implements it; a generic HTTP client's `send` method is not sufficient qualification.
The no-replay boundary covers Versa's own calls, not retries hidden inside an
injected client. Host qualification must audit adapter retries/timeouts separately.
Callbacks are trusted PHP code, not sandboxed plugins or interruptible workers.

Signed inbound webhooks, provider status reconciliation, durable ordering/recovery,
protocol-qualified transports, encryption, moderation, retention, abuse/rate/load
validation and deployment remain outstanding. See [SPEC.md](SPEC.md) and
[ARCHITECTURE.md](ARCHITECTURE.md). Do not activate for live delivery on the strength
of the synthetic fixtures.

## Validation

From an Opus checkout with the approved locked dependencies already available:

```text
php vendor/phpunit/phpunit/phpunit -c addons/versa/phpunit.xml --do-not-cache-result
php vendor/phpunit/phpunit/phpunit --filter 'AddOns\\Versa\\Tests' --do-not-cache-result
php bin/lint-php.php
```

PHP 8.2+, mbstring, and the declared native library floors are required. Tests use
in-memory native-client fixtures and mock storage for the real Wise parser; no
secrets, providers, Docker or dependency installation is required. No live test is
enabled by default.

## Passive package registration

Opus queues the factory in `main.php` for the engine's `OnAppLoaded` behavior.
Keep the factory passive: installation may load it while the add-on is inactive.
Put boot contributions in `AddOnRegistration::contributions()`, not another lifecycle listener.
Use `BlueFission\Services\Mapping::add()` in API/app/default mappings for routes;
leave agent, menu, and console catalogs under their owning consumers' permissions.
Opus registers declared themes as `versa/<theme>` after contributions succeed.
Repeated dispatch does not replay registration, even after failure. Restart the application
after repairing a failed boot; partial PHP effects cannot be rolled back.
