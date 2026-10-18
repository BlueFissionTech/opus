# Command activity

The opt-in command activity service records a versioned, durable receipt around
the shared command host. It does not parse or execute commands itself. Wise and
the existing host policy remain responsible for authorization, confirmations,
continuation ownership and execution. A history record never grants replay.

The first surface covers request, approval/rejection submission, confirmation
request and terminal result. Submission is not proof of an accepted approval.
The recorded result is the command processor's reported status, not independent
proof of external effects. Effects and the underlying execution mechanism remain
`unknown` unless a future authenticated producer contract supplies evidence.

Host code must supply authenticated actor and tenant context, just as it does for
the command processor. Never populate that context from command text or request
body fields. Application identity is configured on the service. Reads use the
same application/tenant/actor boundary; there is no implicit administrator bypass.
Prompt text, arguments, outputs, descriptions, diagnostics, provider metadata,
profile content and executable continuation tokens are excluded from records.

SQLite storage uses the installed DevElation connection and prepared statements.
The operator provides an already opened connection to a private database outside
the web root. Schema installation belongs to the opt-in BlueCore delta in
`datasources/activity/20261018_create_command_activity.php`; the store never
creates or alters tables. Construct the activity service
and pass it as the third argument to `CommandHost`. Existing hosts without this
argument retain their behavior and do not silently start collecting history.

```php
use App\Business\Presentation\CommandResultPresenter;
use App\Business\Services\CommandActivityService;
use App\Business\Services\CommandHost;
use App\Business\Services\SQLiteActivityStore;
use BlueFission\Connections\Database\SQLiteLink;

// $privateDatabasePath and $applicationId are operator-controlled configuration.
$link = new SQLiteLink(['database' => $privateDatabasePath]);
$link->open();
$store = new SQLiteActivityStore($link);
// Run the activity delta during installation before enabling this store.
$activity = new CommandActivityService($store, $applicationId);
$host = new CommandHost($existingProcessor, new CommandResultPresenter(), $activity);
$result = $host->execute($input, $authenticatedContext);
$receipt = $result->activityReceipt();
$history = $host->history($authenticatedContext, after: 0, limit: 100,
    correlation: $receipt['correlation_id'] ?? null);
```

The delta extends BlueCore `Delta` and declares columns with DevElation
`SQLiteStructure`. It executes that scaffold and its compound indexes against the
explicitly bound SQLite connection in one transaction. The installed static
`SQLiteScaffold` selects a default connection and does not propagate failure;
using its structure builder with the injected connection preserves isolation and
observable failure. Schema creation is removed from `SQLiteActivityStore`.

Run this optional directory through the existing BlueCore `DatasourceManager`
installation flow. Bind the same opened `$link` to the delta before discovery:

```php
$app->bindArgs(['link' => $link], CreateCommandActivity::class);
// $migrations is the host's configured BlueCore DatasourceManager.
$migrations->setDeltaDirectory(resolve_path('datasources/activity/'));
$migrations->runMigrations('command_activity');
```

The installed manager records migration names, iterations and status in its
existing MySQL `migrations` table, even though this delta's target is SQLite.
Inspect the recorded status (2 = success, 3 = failure) before enabling recording;
the installed manager catches delta exceptions. Use a dedicated manager instance
for this optional directory so core migration discovery is not redirected.
There is no new parallel migration runner or automatic SQLite-only ledger.
The delta is outside the default core directory, so ordinary installation does
not create an unconfigured private database. A previously applied delta must not
be rerun manually. For rollback, isolate the activity batch and quiesce writers
and readers; the existing `revertMigrations()` flow invokes `revert()`, which
**deletes the receipt table and its data**. Back it up before an intentional
rollback. A failed change rolls back table and index creation together.

`CommandHost` and `CommandActivityService` initialize DevElation `Service`.
`ActivityEvent` initializes `Obj` for behavioral integration and exposes the
closed receipt through `field()`, property reads, `data()` and `toArray()`.
Receipt fields remain immutable across generic assignment, clearing and field
writes; use validated `fromArray()` to reconstruct a record. Behavioral metadata
never becomes a persisted receipt field.

Inject this host into the existing CLI or a connection-scoped terminal factory.
In a BlueCore host that constructs `CommandHost` through its container, the
equivalent argument binding is `$app->bindArgs(['activity' => $activity],
CommandHost::class)`. Keep the database outside the document root with access
limited to the application service account. SQLite3 must be enabled in PHP;
missing support or an unopened connection fails rather than falling back to
volatile memory. No environment variables or dependencies are installed by this
feature.

`activity_receipt` is an additive top-level presentation field, `null` when not
configured. The host populates it separately from provider metadata. It identifies
the latest confirmed record and its correlation, not an authorization token. On a
completion-write exception it refers to the initial request because the final
commit is uncertain, even if storage later reveals a committed outcome. Export
history as JSON from the same read API; it includes no command input for replay.
Pagination uses a strictly increasing sequence local to the application, tenant
and actor, an exclusive `after` cursor, a maximum of 500 rows and an optional exact
correlation filter. Cursors do not expose global insertion counts. Queries
and exports never accept another actor or tenant independently of host context.
Corrupt or unsupported records cause a read failure rather than a successful
empty history. Fields reserved for capabilities, policy/grant/evidence/artifact
references remain empty until a trustworthy producer can populate them. A
decision submission does not assert that its submitter was human.

The store assigns scope-local sequence numbers in the same atomic insert statement,
with a unique scope/order index. It uses SQLite commits and unique event IDs;
duplicate inserts fail. The host performs no automatic append or execution
retry. A database lock fails closed. Operators must arrange backup, capacity,
connection timeout and filesystem permissions; this adapter is for a
single installation with a local SQLite database, not a shared network filesystem
or a distributed cluster. `IActivityStore` is the replaceable persistence boundary.
There are no mutable record filters or public observer hooks in this increment.
There is no deletion API. Manual pruning can invalidate sequence cursors and
approval links; a retention rotation must reset clients' cursors explicitly until
a supported retention/checkpoint contract exists.

When activity recording is enabled, failure to persist the initial receipt stops
dispatch. Failure to persist the result returns a bounded host failure with
unknown effects; it never invokes the processor again. A request without a
terminal receipt requires reconciliation, not an automatic retry. SQLite commit
durability depends on the configured filesystem and SQLite settings; the record
and an external side effect are not one distributed transaction.

This increment does not yet provide a UI, cross-actor auditor policy, retention
jobs, full-text search, workflow/add-on/provider producers, cost aggregation,
cluster storage, stable observer hooks or live production durability proof.
Those remain part of issue #124 rather than implied by this command receipt API.
