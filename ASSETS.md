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

## Package Responsibilities And Runtime Surfaces

| Surface | Authoritative source and owner | Publication / runtime boundary |
| --- | --- | --- |
| Application shell, admin modules, and agent controls | Opus `resource/src/js`, with presentation-specific styles in `resource/src/css` | Entries and copies declared by `tools/asset-pipeline.cjs`; Webpack emits `public/assets`. |
| Theme bundles | Theme-owned `resource/markup/<theme>/src/index.js` and its imports | One selected build theme; `OPUS_ASSET_THEME` does not activate a runtime theme. |
| Theme images, fonts, media, and compatibility scripts | Theme-owned `resource/markup/<theme>/assets/` | Copied as declared build input; migrate script behavior into modules before removing the compatibility input. |
| Add-on presentation and media | The add-on's authored `resource/src` and declared resources | Opus assembles supported asset entries; the add-on owns feature behavior and licensing. |
| Story/pulse, character expression, walkthroughs, and scenario media | The application or add-on that authors the experience | Keep the authored scripts, timelines, model/rig sources, and media provenance there. These are not implicit core Opus entrypoints. |
| Reusable browser bindings, modules, and response/transport behavior | Reactor | Consume package exports from authored modules; request reusable fixes in Reactor rather than copying its runtime into Opus. |
| PHP theme registration, template resolution, and package/host root handling | BlueCore, composed by Opus | PHP serves the selected registered theme and built assets. BlueCore does not own this application's Webpack entry list or authored scenarios. |
| MIX presentation/runtime capabilities | Their owning renderer and protocol packages | Compose through declared application/add-on entrypoints. This build contract does not certify MIX protocol compatibility. |

The source of truth is the owning repository's authored file plus its build or
copy rule. A file's presence under a public URL does not establish ownership or
make it safe to execute. Preserve package versions, source revisions, licenses,
and generated-artifact provenance in release evidence. Avoid embedding private
configuration or model/provider credentials in browser assets.

## Published-Only Changes

Make routine changes in authored sources and rebuild. Never make a release depend
on an unexplained manual edit under `public/assets`; `output.clean` may remove it
on the next build. If an incident response discovers or temporarily requires a
published-only patch, record the exact affected artifact and source revision,
port the change to the owning source, reproduce it through the build, verify the
relevant interaction, and replace the temporary output before promotion. Keep a
rollback artifact until the replacement is verified. An output patch is not a
substitute for the source change.

Do not copy the excluded `resource/markup/default/js` tree back into production
as a shortcut. Compare legacy behavior with the authored source, port only the
required behavior, and remove the duplicate after its callers have migrated.

## Migration Checklist

1. Inventory every live script, style, model, image, video, font, and template.
   Record its URL, authoring owner, source path, generation/copy rule, and license.
   Mark unknown and generated-only files explicitly.
2. Match platform shell and agent-control behavior to Opus sources. Keep
   scenario-specific story/pulse, persona, and media behavior in the application
   or add-on. Send reusable binding/runtime fixes to the owning upstream package.
3. Move required legacy code into authored modules or declare a temporary,
   versioned compatibility asset. Remove conflicting entry names and update
   template/URL references; do not delete a legacy file until callers are known.
4. Select the build theme, verify add-on entry discovery, and install the locked
   frontend graph. Run the validation, asset tests, and build commands above in a
   clean checkout. A manifest test alone does not prove the compiled UI works.
5. Exercise login, navigation, agent controls, theme assets, media loading, and
   responsive/accessibility behavior in the intended host. Check for missing
   resources and retain the previous artifact for rollback.
6. Record source and dependency revisions with the artifact, deploy through the
   host's approved release path, and verify the same interactions after cutover.
   Delete superseded copies only after successful recovery and rollback checks.

The existing manifest tests can also run without installing build dependencies:
`node --test tests/Node/asset-pipeline.test.cjs`. They exercise deterministic
entries, missing sources, theme validation, collisions, and legacy exclusions;
they do not certify Webpack compilation or a deployed application.
