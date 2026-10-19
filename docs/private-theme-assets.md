# Private theme distribution boundary

Public Opus packages use the distributable fallback theme. Snow is separately
licensed operator input; the current permission is one license for Arkheion
only. Another product or public distribution requires explicit human approval
and suitable licensing. Never copy its source, generated output, snippets,
archives, or screenshots into public source, fixtures or documentation.

The reserved names `private-assets`, `snow`, `snow-*`, `snow.*`, and `snow_*`
apply to complete path components, case-insensitively, at every depth. Keep all
private bundle inputs and outputs inside these locations until a separately
authorized private deployment consumes them. Do not rename licensed material
to evade this boundary. Filenames cannot establish licensing or detect copied
content under unrelated names; source review remains necessary.

Git ignores prevent ordinary staging. `npm run audit:public-assets` inspects
the Git index and fails if private files were force-staged or already tracked.
It emits only readiness, a reason code and a count, without private locations
or contents. Run it before committing and on public add-on repositories with
`node tools/audit-public-assets.cjs <repository-root>`. It requires Git and
fails closed when inventory is unavailable. CI runs the same audit.

Git source archives, Composer archives, npm packages and public Docker build
contexts have explicit exclusion rules. Synthetic fixtures check normal and
forced staging, actual source and Composer archives, and npm package listings. These rules
must be preserved when a build supplies a custom ignore file, copies selected
files directly, or assembles a public add-on in a different repository.

Private bundle injection, its version/integrity manifest, Vibe/Reactor adapter,
required-bundle readiness failure, and private image verification are not yet
implemented. Public-context exclusions do not prove an image's contents or
authorize publishing private layers. A licensed operator-supplied bundle and
an authorized private deployment context are required for that proof. Future
upgrade and rollback must replace that private bundle outside source history.
