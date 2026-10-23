# Aimos — native goal and KPI reporting

Executable reporting slice for #79, using native Automata initiatives, KPIs,
observations, conditions and goal managers. See ARCHITECTURE.md for the pre-code
Materia audit and SPEC.md for acceptance and remaining effect/promotion gates.

## Synthetic example and tests

With the host's approved dependencies already installed:

```sh
php addons/aimos/examples/report.php
php vendor/bin/phpunit -c addons/aimos/phpunit.xml --do-not-cache-result
php bin/opus-addon.php validate addons/aimos package
```

The example reports two synthetic tenant scopes, one above and one below a
registration target. Timestamps and values are fixed fixtures, not current event
data. It does not create tenants, accounts, database records, providers or tasks.
Standalone package tests require an Opus host because the authority boundary is
Opus-owned; package-only installation does not supply that host.

## Host composition

Construct `ReportService(application, tenant, tree, observations, context, clock)`
with trusted closures. `tree` returns a `ReportingInitiative` (native Initiative
subclass) with native Kpi/Task records. Native addChild creates relationships.
KPIs require kpi_id, source, unit, formula=sum.v1, integer period_start/period_end,
positive max_age <=86400 seconds and a finite numeric target. Identifiers use
lowercase letters/digits/underscores. Duplicate ids or conflicting targets are
invalid. The report derives native conditions from KPI targets and child status;
other preexisting criteria are rejected, never silently ignored.

`observations(Kpi, AgentRuntimeContext)` returns at most 1000 native Observations
per KPI. Each Context contains application, tenant, metric, source, unit, numeric
value, confidence in [0,1], and quality=synthetic|verified. Native timestamps must
not be future-dated. The half-open period is [start,end); sum.v1 adds each supplied
contribution once. The adapter owns source identity/deduplication and must not
supply repeated deliveries as independent contributions. Any stale included row
makes the entire metric stale; missing/stale values are null, never invented zero.

The host authenticates `context()` and grants aimos.read only to appropriate
actors with the add-on active. `clock()` returns trusted Unix seconds. Explicitly
bind/resolve the service on the host Application and call `registerWith($app)` on
the same Application used by Wise. No load-time registration or network activity
occurs. Wise accepts `get aimos "discover"`, `get aimos "status"`,
`get aimos "explain"` and `get aimos "report"`. These return the same evidence
schema (discover excludes observations). Inspect the inner structured status:
Wise delivery completion alone does not certify a report.

## Effect boundary

Only read behavior is registered. Target changes and trigger activation are
denied without aimos.manage and remain unavailable even with that grant until a
reviewed durable/idempotent effect adapter exists. Native Task records must be
explicitly disabled. Report evaluation never executes them. Durable target
updates, idempotent trigger receipts, audit/scheduling, provider qualification,
tenant provisioning and hosted service promotion are **not implemented** here.

Aimos is an Opus add-on.

Opus queues the factory in `main.php` for the engine's `OnAppLoaded` behavior.
Keep the factory passive: installation may load it while the add-on is inactive.
Put boot contributions in `AddOnRegistration::contributions()`, not another lifecycle listener.
Use `BlueFission\Services\Mapping::add()` in API/app/default mappings for routes;
leave agent, menu, and console catalogs under their owning consumers' permissions.
Opus registers declared themes as `aimos/<theme>` after contributions succeed.
Repeated dispatch does not replay registration, even after failure. Restart the application
after repairing a failed boot; partial PHP effects cannot be rolled back.
