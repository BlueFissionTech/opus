# Opus Runtime Contract Proof

This proof expresses Opus runtime concerns as executable contract examples. The
current source format is JenSS, but the feature is the runtime contract: what the
host declares, validates, records, and learns from.

The scripts focus on package-neutral Opus responsibilities:

- runtime host manifest shape
- route, session, and ledger contracts
- addon and theme lifecycle decisions
- goal, statement, and feedback surfaces for automation
- target syntax for resource maps and LINQR preservation

## Scripts

- `opus-runtime-host.jss` loads a host contract fixture, creates a world/domain
  surface, records route/session/ledger statements, and composes a readable
  audit card.
- `opus-addon-lifecycle.jss` checks addon/theme readiness through policy and
  feedback surfaces.
- `opus-intelligence-feedback.jss` uses correction feedback as a small reusable
  learning surface for contract decisions.
- `targets/opus-resource-map-target.jss` captures desired resource loading and
  mapping behavior.
- `targets/opus-linqr-target.jss` captures desired LINQR block preservation and
  callable query behavior.

The target scripts are intentionally marked optional in the manifest until the
interpreter promotes those surfaces to the same execution tier.

## Validation

Run the contract proof with Jenerator available on Composer autoload:

```powershell
$env:JENERATOR_AUTOLOAD = "<path-to-jenerator-vendor-autoload>"
php examples/jenss/validate.php
```

The validator parses and executes required scripts, then reports optional
contract target gaps without failing the whole proof. Use `--strict` when
optional target gaps should fail the run.
