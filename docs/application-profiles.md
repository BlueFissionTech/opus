# Minimal HTTP application profiles

Set `OPUS_PROFILE` in the host process environment before serving the existing
`public/index.php` entrypoint. `api` and `dashboard` use the same BlueCore engine,
router and authentication gateway as the platform. An unset value or `platform`
keeps the full application. Unknown values fail closed with JSON 503.

| Profile | Published routes | Required services |
| --- | --- | --- |
| `api` | GET `/health`, `/ready`, `/api/info` | PHP, installed Composer dependencies, application key and writable session storage |
| `dashboard` | API routes plus GET `/login`, POST `/login`, GET `/dashboard`, POST `/logout` | API requirements plus configured MySQL authentication storage for credential login |

These profiles do not register administration mutations, chat, Wise tools,
provider integrations or activated add-ons implicitly. Host-authored extensions
and mappings remain trusted executable code; a profile does not sandbox them.

## Configuration

- `APP_KEY`: existing application key supplied through secret configuration.
  Never use a shared example key.
- `APP_NAME`: optional display name, escaped by the package renderer.
- `OPUS_ADDONS`: comma-separated lowercase installed add-on names, without spaces.
  Empty means none. Selected add-ons must already be active and have an available
  primary file. Selection never installs or activates them.
- Dashboard database: `MYSQL_DB_HOST`, `MYSQL_DB_PORT`, `MYSQL_DB_NAME`,
  `MYSQL_DB_USERNAME`, `MYSQL_DB_PASSWORD`, using the existing database contract.
  Use the container service name when running in a container. The API profile
  does not require optional infrastructure unless selected add-ons need it.

The loader validates the entire selection before running any selected entrypoint
and does not load unselected active add-ons. Selection opts into each add-on's
requirements and effects; arbitrary add-on boot is not transactional.

All PHP configuration scripts selected by the host configuration path are parsed
before any executes. This validates syntax, not the safety of trusted code.
Runtime failures and unexpected bootstrap output are also caught. Bootstrap failures produce
`{"ok":false,"error":"application_unavailable"}` with HTTP 503 and `no-store`,
without exception text, paths or provider output. Install/autoload failures before
the application boundary runs still require server diagnostics.
The boundary records the exception type, source basename and line in PHP's
server error log. It does not record exception messages, arguments or full host
paths. Configure a writable, access-controlled log destination for the host.

## Health, identity and routing

`/health` returns `{"ok":true,"status":"live"}` only after the selected
registration and mappings boot. API `/ready` returns
`{"ok":true,"status":"ready"}` for that minimal surface. Dashboard readiness
also opens the database and checks `users`, `credentials` and `login_attempts`.
Missing tables or connection failure produce HTTP 503 with
`{"ok":false,"status":"unavailable"}`. These read-only checks never migrate,
create users or call inference providers. Add-on-specific health is not inferred
from a loaded entrypoint.

Dashboard entry requires the shared session authenticator. Sign-in uses the
existing credential controller; sign-out removes the session. Unsafe HTTP methods need
the current CSRF token. Invalid tokens return JSON 403 before controller
construction. Authentication does not grant an administrator role or tenant
access; the starter contains no privileged controls.
The entrypoint reuses a session already started by the PHP runtime. Both automatic
and application-owned session startup use the same authentication and CSRF state.

Only registered HTTP routes dispatch. Unknown paths return JSON 404; transport
method overrides from query, form or cookie return JSON 400. This prevents
fallback to service messages or static-file lookup. Matching uses the engine's
mapping collection and Uri matcher; controller dispatch stays in the engine.

Dashboard views in `resource/markup/profile` use `VibeThemeRenderer` and the
existing host theme-root override, with packaged templates as the default.
They require no optional assets or external fonts/CDNs and show no simulated
financial or operational metrics.

## Verification and remaining release gates

```text
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter 'ApplicationProfileHttpTest|SelectedAddOnManagerTest'
```

The HTTP suite starts isolated loopback PHP servers and invokes the real
entrypoint. It checks repeated API requests, media/status/body contracts, route
isolation, configuration parsing before effects, unavailable add-ons, CSRF denial,
real session authentication, package rendering and revocation. It uses an
ephemeral key and synthetic server-owned session. The add-on tests execute
temporary entrypoint fixtures and prove complete-selection validation.

This does not prove credential enrollment against a freshly migrated database,
clean-volume initialization/restart, deployed TLS/session policy, or Compose
startup. Those release gates remain under issues #25, #20 and #31. Role, grant,
tenant and membership administration also remain separate. Do not call the full
profile release complete from loopback tests or schema availability alone.
Local Compose files remain installation-owned rather than distributed here.

## Published endpoint qualification

After the approved local service profile starts, qualify its actual published
origin with `php bin/verify-application.php http://localhost:8080 api` (or
`dashboard` for that profile). Use a supported, security-qualified PHP runtime
with ext-curl and the installed locked dependencies. This command does not
install packages, start containers, modify configuration, or provision users.

Exit zero requires HTTP 200, JSON media type and the exact typed public
contracts at `/health`, `/ready` and `/api/info`. A redirect, HTML error page,
wrong profile, connection failure or unavailable readiness is a failure. Requests
use native DevElation Curl, bounded time/body limits, TLS verification, no
redirect following and no automatic retries. Origins cannot contain credentials,
paths, queries or fragments. Only request an operator-approved origin.

The JSON receipt exposes check names, status codes and fixed reason codes, not
response bodies, exception text or connection details. A receipt qualifies only
these public endpoints at observation time. It does not prove authenticated
dashboard behavior, tenant isolation, an add-on or governor, durable workflow
recovery, image provenance, or production readiness. Do not report startup
success from container state or a PHP-extension healthcheck alone.

This is a qualification command, not a Compose launcher or an automatic startup
gate. An installation's launcher must run it against the published origin after
starting **or reusing** the stack and propagate any nonzero exit before reporting
application readiness. The launcher must also reject missing required recipes;
this command does not validate Compose files. Until that integration and the
actual published origin have been verified, local startup remains unqualified.
