# Asset Build Contract

Opus owns authored frontend sources beneath `resource/src` and theme-specific
sources beneath `resource/markup/<theme>`. Webpack publishes generated files to
`public/assets`; generated files are never an authored source of truth.

## Commands

Install the exact dependency graph before running asset validation or builds:

```powershell
npm ci
npm run assets:validate
npm run test:assets
npm run build
```

`npm run watch` rebuilds authored sources as they change. `npm run start`
remains a compatibility alias for the same watch process; Opus's PHP web host
serves the generated `public/assets` files.

## Theme Selection

`OPUS_ASSET_THEME` selects the compiled asset theme and defaults to `default`.
Theme names use lower-case letters, numbers, and hyphens, and must provide:

- `resource/markup/<theme>/src/index.js`
- `resource/markup/<theme>/assets/`

This build-time setting does not select a user's runtime presentation theme.
Runtime theme registration remains an application concern.

## Add-On Entries

The build discovers immediate directories beneath `addons` in sorted order. An
add-on may expose either or both of these entrypoints:

- `addons/<name>/resource/src/module-<name>.js`
- `addons/<name>/resource/src/<name>.js`

Add-on names follow the platform's lower snake-case lifecycle convention. Build
entry names replace underscores with hyphens, so `sample_tools` publishes
`module-sample-tools` or `sample-tools`. Entry names must not collide with core,
theme, or other add-on entries. Directories without asset entrypoints are
ignored; malformed names are rejected only when they expose an asset entry, and
collisions always fail validation.

## Source Ownership

The build copies the selected theme's `assets` directory as compatibility input
until those authored files are migrated into modules. The duplicate
`resource/markup/default/js` tree is legacy reference material and is excluded
from production copying. New or changed behavior belongs in `resource/src`, the
selected theme source tree, Reactor, or another owning package rather than in a
published `public/assets` file.

The validation command checks every configured entry and copy source before
Webpack starts. Missing files are reported together with the expected path and
the dependency-install requirement where applicable.
