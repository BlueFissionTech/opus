# Repos

Repos is the document and storage add-on for Opus. This first increment supplies
typed document, folder, version, blob-reference, sharing-policy and result
records, plus a transactional small-file store. It is not yet an authorized
document application or a public storage service.

## Available

- Inject a `BlueFission\Connections\Database\SQLiteLink` into
  `AddOns\Repos\Infrastructure\SQLiteDocumentStore`.
- Explicitly call `install()` on an empty, dedicated store to create the schema.
  Construction and package registration do not create tables or open endpoints.
- `write()` creates a document at expected version zero or appends a version.
  Metadata, bytes and retry receipt commit in one transaction.
- `find()` and `documents()` return metadata and opaque references only.
  `readBytes()` is a separate binary transfer API and verifies digest and length.
- `deleteDocument()` checks the current revision and retention of every version.
  It removes content but preserves a document tombstone and retry receipts so
  a delayed write retry cannot resurrect deleted content.
- `uninstallEmpty()` only removes empty tables. It refuses stores with documents,
  including deletion tombstones.

The adapter accepts files up to 65,536 bytes. IDs and display names are not
filesystem paths. Folder and share records are validated contracts only:
folder assignment is rejected by this adapter and no sharing route is installed.
The package theme is an informational availability page; it has no document UI.
No agent map is declared. The validator's `agent_mapping_missing` warning records
that agent command access remains disabled for this persistence increment.
Each retry must preserve its original document, content, expected revision,
timestamp and retention deadline. Changed retries conflict.

## Composition and security

`DocumentStore` is a replaceable persistence contract. **It does not authorize
requests.** Hosts must establish application/tenant scope and authorize ownership,
membership and the requested operation before invoking it. Do not register raw
store methods as public routes or agent tools. Keep storage handles inaccessible
to untrusted code.

The SQLite adapter extends DevElation `Service`, initializes its parent, and
supports the actual application's dependency resolution and registered native
actions. It does not automatically broadcast storage results or confer
permissions through inheritance. The host-facing orchestration and bounded Wise
surface are subsequent work; their tests must include denial, sharing, observer
privacy and native broadcast failure recovery.

For explicit native registration after host configuration, resolve then delegate
the instance. This preserves constructor injection and the handler callback scope:

```php
$application->bindArgs(['link' => $link], SQLiteDocumentStore::class);
$application->delegate('repos.storage', $application->resolve(SQLiteDocumentStore::class));
```

This registration alone performs no authorization and should remain internal.
Tests install a read action explicitly; there are no default storage actions.

Authority and integrity records are immutable. Exact-byte validation, hashing,
retry fingerprint encoding and prepared SQL bindings intentionally do not use
global normalization filters: a filter must not attest to different bytes from
those stored. Connection management and behavioral composition use DevElation.

## Validation

With the normal package or host dependencies already installed:

```sh
php vendor/bin/phpunit --configuration phpunit.xml --do-not-cache-result
```

Run this in the package for package-only checks; the host suite includes the same
tests through `tests/Unit/ReposAddOnTest.php`. Tests create and remove temporary
SQLite files. No network, model provider or external service is required.

The suite covers binary reopen, idempotent retries, stale revisions, scope and
owner filtering, receipt-failure rollback, checksum/length corruption, retention,
deletion replay, non-destructive migration, passive registration and actual
DevElation application injection/action routing. It does not qualify deployment,
backup durability, encryption, malware scanning, multi-writer load, or authorized
HTTP/Wise operations.
