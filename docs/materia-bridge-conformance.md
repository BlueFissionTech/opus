# Native identity bridge conformance

This is a partial, executable conformance slice for issue #107, not a complete
Materia matrix or a release qualification. Installation and class availability
do not establish cross-package behavior. The regression calls the real installed
BlueCore bridge with the real Presence envelopes; it does not supply a substitute
bridge, catch an exception as success, or modify an upstream package.

The machine-readable companion is `materia-conformance.json`. It binds this
observation to the lock hash and inventories every directly required Materia
package. Packages not exercised here remain `not_assessed`, not supported. The
snapshot is failed/partial and is not a full-graph certification. The lock digest
normalizes CRLF to LF so Git checkout settings cannot alter the evidence identity;
all other content remains significant. A portability control covers both forms.
Dependency
adoption requires a newly observed snapshot; editing a version label is not proof.

## Required boundary

An immutable context containing tenant, application, actor and session identifiers
does not authenticate anyone. With no host-owned authenticator, both a supported
host and an unsupported host must return a native, unbound `BridgeResult` with a
valid non-success reason, no principal or allowed access, and no secret payload.
The context must remain unchanged. No provider or session store is needed for
this negative case. The test does not choose the upstream provider-binding API.

## Observed package pair

| Owner / boundary | Exact source | Observation |
| --- | --- | --- |
| DevElation 1.3.47 primitives and objects | `4b9079b8b34010ddd5e0f92774ae9abafbe9ea77` | Shared foundation; this slice does not qualify its complete API |
| Presence 0.1.0-alpha.3 immutable envelopes | `5c01dca824a2bccb2c899b59ab9587d21b715515` | Constructor-owned snapshots and typed denial controls |
| BlueCore 0.1.4-alpha authentication bridge | `99b1789d162adae71320762917802fae57bd1667` | Factory reports available, but unauthenticated binding throws `ArgumentCountError` |

The installed BlueCore bridge reads legacy mutable context fields and constructs
`BridgeResult` without its now-required `bound` and `reasonCode` arguments. Presence
requires immutable, constructor-owned envelopes. Even denial cannot complete on
this pair. Class/interface existence is therefore insufficient compatibility
evidence. The repair belongs to the native bridge, not a copied authentication
implementation in Opus. Do not weaken Presence immutability to make this pass.

## Validation and promotion

```sh
php vendor/phpunit/phpunit/phpunit --do-not-cache-result tests/Unit/NativePresenceBridgeConformanceTest.php
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

The bridge cases deliberately assert the required behavior and remain red on the
incompatible pair. The immutable-envelope controls distinguish native envelope
support from bridge interoperability. Do not skip these cases, invert them to
expect the exception, or advertise a passing factory check as conformance.

The recorded PHP 8.2.35 CLI run executed 6 tests / 41 assertions: four controls
passed and both native bridge cases errored. This is failing-before evidence,
not a passing integration result. The snapshot-lock check requires new evidence
when the dependency lock changes.

Before enabling identity integration, adopt a reviewed compatible native release
pair under the normal dependency approval process and rerun these tests plus
positive authentication, tenant/revision/expiry/revocation, middleware termination
and installed host integration tests. These negative cases do not prove those
positive or consequential paths. Routing, clean installation, provider behavior,
deployment, performance and the remainder of the direct dependency graph remain
outside this slice. Issue #107 remains open.

No packages, credentials, schemas, network services, or live identities are
created by this test. The existing pre-autoload dependency metadata audit remains
passive and separate; it is not changed into a provider-construction probe.
