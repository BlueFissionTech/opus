# Repos architecture

The intended application boundary authorizes requests before document
orchestration. A future host service will receive the application's tenant,
actor and explicit grants, intersect them with ownership or an unexpired read
share, and pass a bounded operation to the injected document store. That
authorization surface is not implemented in this persistence increment.

The storage contract is document-specific. It does not expose SQL, local paths,
credentials or a general database API. The first adapter uses a host-supplied
DevElation SQLiteLink. Prepared statements are its driver boundary. Small binary
objects and metadata share a transaction so failure cannot publish a document
whose content was not committed.

Versions carry opaque blob references, byte counts and SHA-256 integrity values.
Exact byte hashing is an integrity boundary; application filters cannot change
which bytes a digest attests to. Logical folder and document IDs are validated
independently of filesystem semantics.

The planned Wise adapter must expose only metadata discovery/status through the
shared processor contract. Upload/download bytes belong at a separately
authorized application boundary. Future observer summaries must carry operation
and status, not document content, paths, actor identity, tenant identity or
sharing recipients. Neither adapter nor observers are activated here.

Package migration is explicit and separate from passive registration. Upgrade
failure must preserve the previous schema and data. Downgrade refuses a populated
store; destructive data removal requires a separately authorized workflow.

The SQLite adapter extends Service and initializes it, with an injected link and
a replaceable DocumentStore contract. Actual Application delegate resolution and
an explicitly registered native read action are tested. Inheritance does not
turn arbitrary public calls into action events, nor provide access control.

Immutable records use exact-byte predicates. A characterization test proves
that a global `_in` filter can otherwise validate a normalized identifier while
the immutable record retains the unsafe original. Native predicates and hashing
at this boundary deliberately exclude such mutation. SQL binding and transaction
handling are similarly confined to the adapter's driver boundary.

Install is explicit and repeatable for this initial schema. No previous Repos
schema is claimed compatible. DDL, metadata, bytes and receipts use transactions;
reopen tests do not establish process-crash durability or multi-writer load
qualification. Deletion removes bytes but retains tombstones and receipts.
Their purge policy remains a separately designed retention operation.
