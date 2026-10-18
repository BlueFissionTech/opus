# Add-on organization

`AddOnOrganizationService` stores feature groups, ordered add-on membership, and
per-add-on tags for one application and optional tenant. A group can include many
add-ons, and an add-on can belong to many groups. An installation starts with an
empty organization. Names never imply membership or dependencies.

This is a callable service and persistence increment for #120. The existing admin
screen and Wise catalog are not yet connected to it. It does not enforce lifecycle
impact or claim that the full organization UI is delivered.

## Host binding and authorization

Construct the service with a store, a host-selected application ID and tenant ID,
an authorization callable, and an installed-inventory callable. Do not populate
these dependencies or the actor ID from unrestricted request fields. Both HTTP
and command adapters must resolve identity through their authenticated host
context and invoke this same service. No route or console command is registered
until that policy binding exists.

The authorization callable receives `application_id`, `tenant_id`, `actor_id`,
`action`, and `target`. It must return the boolean `true`; missing actors, any other
result, and policy exceptions deny access. It must consult the host's authoritative
role, tenant, and add-on grants. A supplied role list or an add-on tag is never a
grant. The callable is trusted code and receives group metadata before deciding
whether it can be disclosed.

| Action | Required authority |
| --- | --- |
| `view` | Read organization in the bound application/tenant |
| `view_addon` | See the installed add-on identified by target `id` |
| `view_group` | See the group under its `visibility_policy` |
| `manage` | Change organization in the bound scope |
| `manage_group` | Create/change both the existing and proposed group policy |
| `manage_addon` | Change organization for each added, retained, or removed member |
| `audit` | Read the entire scope's organization audit, including hidden group references |

`visibility_policy` is an opaque host policy reference, defaulting to `operators`.
The service does not interpret it as a role or permission. The host must reject
unrecognized policies and unauthorized changes to that reference. Hidden groups
are omitted from group filters and add-on memberships. Hidden add-ons do not
contribute to visible member lists or visible group state. The scope revision is
a concurrency marker shared by authorized readers; it is not an activity metric.

## Operations

- `catalog(actorId, filters)` returns `revision`, visible `groups`, and visible
  `items`. Groups sort by `order` then `slug`; membership preserves explicit input
  order, and items sort by name then stable ID.
- `saveGroup(actorId, group, expectedRevision)` creates or replaces a group.
  Fields are `slug`, `name`, `description`, `order`, `display_mode` (`section`,
  `accordion`, or `page`), `visibility_policy`, and ordered `members` (installed
  add-on IDs). The slug is the scope-local stable key; renaming the display name
  does not change membership. A new slug creates another group.
- `deleteGroup(actorId, slug, expectedRevision)` removes only organization metadata.
  It does not uninstall or deactivate any package.
- `tag(actorId, addonIds, tags, expectedRevision)` replaces tags for all selected
  add-ons atomically. An empty tag list clears tags. Unknown add-ons, duplicate
  values, or one denied target reject the entire operation.
- `history(actorId, afterRevision, limit)` reads scope-local audit records, with a
  maximum page size of 500. Audit authority is independent from ordinary viewing.

Example, after constructing `$organization` with authoritative host dependencies:

```php
$revision = $organization->catalog($actorId)['revision'];
$revision = $organization->saveGroup($actorId, [
    'slug' => 'customer-support',
    'name' => 'Customer support',
    'description' => 'Communication and retrieval tools',
    'order' => 10,
    'display_mode' => 'accordion',
    'visibility_policy' => 'support-operators',
    'members' => ['company/messages', 'company/search'],
], $revision);
$organization->tag($actorId, ['company/messages'], ['customer-facing'], $revision);
```

Do not retry stale updates blindly. Reload the authorized view and let the operator
reconcile their intended change. Database failure returns a bounded storage error;
it does not claim that a write succeeded.

## Inventory and filters

The host inventory callable returns records with a stable string `id`. Optional
fields are `name`, boolean `active`, `vendor`, `health`, `update`, `configuration`,
`tenant_availability`, and a list of declared `capabilities`. Unknown activation
remains `null`; other missing status fields remain `unknown`. Only these fields
are exposed. Arbitrary inventory metadata, secrets, and dependency bodies are
not copied into the response or organization storage. Inventory failure prevents
a successful listing or membership mutation.

Filters are `search` (case-insensitive name/ID substring), boolean `active`, exact
`vendor`, `health`, `update`, `configuration`, `tenant_availability`, and membership
in `capability`, `group`, or `tag`. Tags never satisfy capability filters. Capability
claims must come from the host's authoritative inventory. Organization methods
never write inventory or manifest data.

Group state summarizes only visible installed members: `empty`, `complete`,
`partial`, `inactive`, or `unknown`. This is a presentation summary, not a lifecycle
approval or a dependency solver. A removed package can be removed from membership
without pretending it remains installed.

## Storage and migration

`SQLiteAddOnOrganizationStore` accepts a configured, open DevElation `SQLiteLink`.
Keep its database outside the public document root and restrict filesystem access.
Construction never creates schema. The opt-in BlueCore delta is
`datasources/organization/20261018_create_addon_organization.php`; bind the same
connection to `CreateAddOnOrganization` and run this directory through a configured
`DatasourceManager`. The normal core migration directory does not run it implicitly.
The migration ledger remains the manager's existing configured ledger; the SQLite
connection controls only these organization tables.

```php
App::instance()->bindArgs([$organizationLink], CreateAddOnOrganization::class);
$manager->setDeltaDirectory(resolve_path('datasources/organization/'));
$manager->runMigrations('addon_organization');
```

The delta creates `opus_addon_organization` and `opus_addon_organization_audit`.
Each scope stores a revision and JSON document. A write obtains an immediate
SQLite transaction, checks the expected revision, and writes the document and
audit receipt together. Audit failure rolls back the document. Receipts retain
actor ID, action, target IDs, and timestamp, without private input payloads.
Documents are bounded to 1 MiB; malformed JSON fails closed.

Re-running the delta directly fails without erasing data; use the manager ledger
to track application. `revert()` explicitly removes both tables and their data.
Back up before a deliberate rollback. Tests exercise the actual delta and SQLite
transactions; a live deployment's migration-ledger integration is not yet proven.

## Extension event

`opus.addon.organization.changed` runs after the transaction commits. Its single
array argument contains `application_id`, nullable `tenant_id`, `revision`, and
`action`. It excludes actor, group, and tag content. Observers cannot rewrite
state or authorization. An observer exception does not roll back committed state
or cause the caller to repeat a completed write. The durable audit table is the
authority; event delivery is best effort.

## Remaining #120 delivery gates

Connect the authenticated HTTP, Wise, and administration UI adapters to this
service; prove the interactive 100-add-on filtering and bulk-edit workflow. Add
authoritative lifecycle-impact analysis for manifest dependencies, capability
consumers, agents, routes, jobs, and configuration references, with dependency
fail-closed enforcement and explicit confirmation of non-blocking feature impact.
Integrate those decisions and organization audit records into the unified timeline.
These are required before closing #120.
