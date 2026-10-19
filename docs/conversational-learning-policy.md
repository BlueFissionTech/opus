# Conversational learning policy composition

`ConversationalLearningCatalog` composes package, application, tenant, and principal
settings, then exposes the two documented conversation extension filters. The
catalog describes configuration; reading it does not construct a model, collect
a conversation, train a classifier, or activate a provider.

## Capture decisions

Unknown-intent capture requires `capture_unknown_intents: true` in package defaults
and no explicit non-true value at a more specific layer. Omitted layer values
inherit the existing decision. Boolean `false` denies capture. Explicit malformed
values such as null, strings, numbers, and arrays also deny capture; truthiness
does not grant consent. A later principal policy cannot reverse an application
or tenant denial.

The settings filter can further disable capture, but cannot reverse these denials.
The configuration filter can further disable the resulting decision, but cannot
reverse a denial from the settings stage. These checks run after filter output is
applied. Safe unrelated customization keys remain available.

Scoped fallback `capture_candidate` hints are the conjunction of the immutable
package hint and the final effective capture decision. Disabling capture therefore
also disables the unknown-intent fallback hint. Package defaults and fallback
seeds remain unchanged for other scopes.

Example:

```php
$configuration = $catalog->configurationFor(
    $profile,
    application: ['capture_unknown_intents' => true],
    tenant: ['capture_unknown_intents' => false],
    principal: ['capture_unknown_intents' => true],
);
// Both effective capture_unknown_intents and the fallback capture_candidate
// are false. Neither extension filter may turn them back on.
```

The existing `mode` values (`shadow`, `review`, `active`) retain their configuration
semantics. A mode value does not grant permission to train, persist private data,
or promote a model. Explicit approval and the owning runtime's authorization
remain separate requirements.

## Mandatory learning safeguards

The host preserves these values in the package projection and after scoped
configuration filters:

| Setting | Required value |
| --- | --- |
| `automatic_observation` | `false` |
| `trusted_artifacts_only` | `true` |
| `serialized_state_enabled` | `false` |
| `scope_isolation_required` | `true` |

Existing protections also keep private-conversation and provider-payload capture
disabled, review and persistence required, candidate status pending, and generated
fallback text out of training data. A package configuration enabling automatic
observation is rejected; weaker values for the other reserved safeguards are
normalized to the mandatory values.

## Scope and proof limits

This closes the configuration-denial and post-filter guard gaps within #99. It
does not certify production learning persistence, application-level profile
isolation, live grant/revocation storage, cross-channel resource adapters, model
promotion, or administration UI. Those remain required under #99 and its upstream
contracts. The host must still pass the resulting configuration to a conformant
runtime and enforce current policy at execution. No provider or training call is
made by this increment.

Run:

```sh
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter 'ConversationalLearningCatalogTest|DevElationHelperUsageTest'
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

The regressions cover every policy layer, strict malformed-value denial, both
filter stages, immutable package seeds, fallback hints, and protected learning
flags while preserving safe extension metadata.
