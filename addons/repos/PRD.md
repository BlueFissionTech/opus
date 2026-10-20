# Repos first executable slice

Repos owns document metadata, folders, immutable versions, blob references,
explicit sharing policy and storage composition. Its source lives in
`addons/repos`, with the `AddOns\Repos` namespace. Source presence is not
installation, governor enrollment or activation.

## Acceptance

- A host-authorized actor can create and read small files, list authorized
  document metadata and append a version using an expected revision.
- Application and tenant scope, membership and grants come from the host.
  Document identifiers never become filesystem paths.
- A retry identifier is bound to the exact operation and content. An identical
  retry returns the prior receipt; a different request under that identifier
  conflicts. Concurrent stale revisions cannot overwrite a newer version.
- Metadata, content and retry receipts commit together. Partial writes roll
  back, and every binary read checks the stored byte count and SHA-256 digest.
- Write, delete and share require explicit grants. Retention prevents premature
  deletion. Sharing does not grant permission to write or reshare.
- Wise exposes bounded discovery and status. Binary content never enters
  command results, observation payloads or agent context.
- An injected store owns persistence. The first local adapter uses DevElation
  SQLiteLink and a package-owned, reversible schema; tests exercise actual
  SQLite transactions, reopen/recovery and competing revisions.

## Boundaries

The first slice is not an S3/CDN, spreadsheet, office, RAG, collaboration or AI
generation service. `service-repos` and `repo.bluefission.com` remain gated on
encryption, quotas, malware scanning, backup/restore, retention and load proof.
No public endpoint or provider connection is enabled by installing source.

## Increment status

This increment implements immutable records and the injected local SQLite store:
small-file create/read/list/version, atomic retry receipts, integrity checks,
retention-aware deletion and non-destructive schema rollback. The store has no
authorization role and is not exposed to users or agents.

Host-scoped authorization, folder persistence, sharing enforcement, bounded Wise
discovery/status and operational Service observations remain required for the
complete first application MVP. Those requirements are not fulfilled by record
validation or the store's owner query. No production or full-MVP claim follows
from the persistence tests.

## Architectural obligations

The document orchestration service must initialize DevElation Service and use
injected collaborators. Native behavior registration and failure recovery need
tests through the actual application, not only direct constructors. Immutable
authority and integrity records deliberately remain typed readonly values:
mutable behavioral state must not rewrite their fields.

The known installed Application broadcast recovery defect remains a gate for
parent-routed observations. It must not be hidden by private dispatcher resets,
copied routing machinery or silently discarded acceptance tests.
