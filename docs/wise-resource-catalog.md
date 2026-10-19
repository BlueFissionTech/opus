# Wise resource catalog integration

Opus consumes `BlueFission\Wise\Res\ResourceCatalog` from the locked Wise
`v0.1.0-alpha.2` release. Canonical names, resource classes, aliases and declared
unavailable capabilities come from that package. `mapping/app.php` contains only
explicit host verb bindings and local application resources, without a second
inferred Wise type manifest.

`WiseResourceCatalogRegistration::inspectCatalog($profile)` reads and validates
the complete catalog before any application mutation. It returns `ready`,
`reason`, `bindings`, `aliases` and `unavailable`. Inspection loads class metadata
but does not construct resource instances, providers, or an application.

`installMappings($application, $profile, $actions)` repeats that preflight, then
registers canonical definitions and their declared aliases. It returns the same
diagnostics plus `registered`. Aliases inherit explicit verbs from their canonical
identifier. An unavailable resource, including an alias to it, never receives a
delegate or command registration. Invalid alias targets fail before registration.

With `WISE_INTEGRATION=required`, a missing catalog or available resource class
raises the existing actionable missing-type error. With `optional`, either case
skips the entire Wise mapping. Preflight failure does not leave a partial Wise
registration. Local `user` and `skill` mappings remain outside this boundary.

## Migration

- Search commands use canonical `search`. The formerly local `web` search name
  now follows Wise's declared alias for `website`; `get web` maps to the browser's
  `browse` method, and `show web` to `viewPageContent`.
- `calculator`, `encyclopedia`, `filemanager`, `functions`, `goals`, `profile_steps`
  and `web` follow the released catalog. Opus does not fabricate `task` as an alias
  for `todo`; callers must use a declared resource name.
- `resource`, `message` and `transcript` are reported as `not_implemented` by the
  locked catalog. Opus no longer substitutes `ResourceHelper` for `resource`.
- Private profile resources may appear in the catalog without host verb bindings.
  They still need explicit scoped adapters and current authorization; registration
  does not grant permissions, inject a private store, or establish execution proof.

## Construction and proof boundaries

Catalog inspection is side-effect-free with respect to resources and providers.
Actual registration uses the application's existing `delegate()` contract. In the
supported DevElation runtime this can eagerly resolve Service subclasses. The host
owns construction, lifecycle and execution authority. Registration is not claimed
to be a provider-free preview or a transaction around arbitrary host callbacks;
only missing-dependency and catalog validation failures are caught before writes.

Tests cover installed definitions and aliases, required failure, optional atomic
skip, unavailable diagnostics, invalid catalogs, unchanged local mappings, and the
real Application delegation boundary with a controlled resolver. They do not call
providers or certify live profile adapters, browser services, or inference tools.
