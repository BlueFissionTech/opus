# Contributing to Opus

Opus composes the application platform: runtime registration, add-on lifecycle,
command hosts, presentation, and integration boundaries. Reusable algorithms,
protocols, and language behavior belong in their owning packages. Start with an
issue that states the user outcome, scope, and observable acceptance criteria;
link any upstream dependency instead of duplicating its implementation here.

## Prepare a change

1. Read [the specification](SPEC.md), [architecture](ARCHITECTURE.md), and
   [testing guide](tests.md) for the affected area. Use the
   [product requirements](PRD.md) to distinguish implemented behavior from
   planned work.
2. Work on an `issue/<number>-<slug>`, `feature/<number>-<slug>`, or
   `hotfix/<number>-<slug>` branch. Do not commit directly to `main`,
   `master`, `development`, or `staging`.
3. Keep the pull request bounded to one reviewable behavior or documentation
   slice. Include its issue, acceptance evidence, validation commands, and
   any known limitations. Do not merge without the required human approval.

## Validate and protect users

- Keep PHP changes compatible with PHP 8.2 and preserve public APIs unless an
  explicit migration is reviewed. Prefer existing Blue Fission contracts over
  new dependencies or duplicate helpers; use native PHP within a primitive's
  implementation when that is the appropriate boundary. Add hooks or filters
  only for a stable, documented extension purpose.
- Add focused tests for behavioral changes. Run the relevant commands in
  [tests.md](tests.md); optional service tests remain opt-in.
- Keep credentials and private assets out of commits, fixtures, logs, and PRs.
  Document required environment variables without committing `.env` files.
- Treat dependency installation, production deployment, and license or asset
  rights changes as separate, explicitly reviewed decisions.

For usage questions or a proposed change that crosses package boundaries,
open an [issue](https://github.com/BlueFissionTech/opus/issues) before
expanding the pull request.
