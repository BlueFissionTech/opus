# Consulting guidance

Opus exposes optional advice through `ConsultingGuidanceInterface`. Providers own
the ranking and reasoning algorithms. The host checks the response scope,
candidates, expiry, evidence freshness, and approval policy before returning it.
Neither an advisory result nor an override authorizes execution.

## Application registration

`AppRegistration` binds the interface to `UnavailableConsultingGuidance` and
registers the lazy `guidance` service. Applications can replace the interface
binding after the default registrations and before resolving the service:

```php
$app->bind(
    \App\Domain\Guidance\ConsultingGuidanceInterface::class,
    ApplicationGuidanceProvider::class
);
```

A provider implements `advise(GuidanceRequest): GuidanceOutcome`. For explicit
composition, construct `new ConsultingGuidanceService($provider)`. Omitting the
provider returns `unavailable`; startup does not require an advisory provider,
credentials, database, or model. Provider exceptions become a sanitized
`guidance_provider_failed` result, without exposing exception contents.

## Version 1.0 records

Both records implement `JsonSerializable` and expose `toArray()`. Constructors
detach PHP references and reject executable objects, resources, nonfinite
numbers, excessive nesting, and unsupported versions. DevElation JSON helpers and
strict schema checks at this boundary intentionally keep executable values out
of the data records. Future incompatible schemas require a new version.

```php
use App\Domain\Guidance\GuidanceRequest;
use App\Business\Services\ConsultingGuidanceService;

$request = new GuidanceRequest([
    'version' => '1.0',
    'id' => 'maintenance-review-1',
    'question' => 'Which maintenance work should receive attention first?',
    'scope' => [
        'application' => 'operations',
        'tenant' => 'team-a', // Explicit null for an application-wide request.
        'principal' => 'operator-1',
    ],
    'evidence' => [[
        'id' => 'survey',
        'reference' => 'record:survey-1',
        'status' => 'current',
        'expires_at' => 1800000000,
    ]],
    'constraints' => ['maximum_hours' => 8],
    'regime' => ['maintenance' => true],
    'efficacy' => ['confidence' => null],
    'candidates' => [['id' => 'accessibility'], ['id' => 'indexing']],
    'approval_policy' => ['required' => true],
]);

$service = new ConsultingGuidanceService($provider);
$outcome = $service->advise($request, $currentEpochSeconds);
```

The host supplies the clock, authenticated scope, and approval policy; do not
derive them from unchecked client input. Tenant null is explicit, never inferred
from a missing field. This service matches identity; it does not authenticate
callers or load evidence. Evidence references must already be authorized for the
request scope. Providers must not retrieve unrelated tenants' data.

Request evidence requires a unique ID, reference, and status (`current`, `stale`,
`missing`, or `unknown`). Optional `expires_at` is a nonnegative epoch second;
null means unspecified. Candidate IDs must be unique. Candidate records may carry
provider-owned scores and metadata. Constraints, regime, and efficacy carry inert
provider-owned context. Omitted approval policy defaults to requiring approval.

An outcome requires `request_id` and the same `scope`. Its fields are:

| Field | Contract |
| --- | --- |
| `version` | `1.0` |
| `status` | `advisory`, `review_required`, `unavailable`, `expired`, or `invalid` |
| `recommended` | Request candidate ID, or null |
| `alternatives` | Ordered ranking of request candidate records with numeric `score`; unique IDs |
| `reasons`, `tradeoffs` | Lists of explanatory strings |
| `confidence` | Number from zero to one, or null for unknown |
| `risk` | `low`, `medium`, `high`, or `unknown` |
| `value_gates`, `constraint_gates` | Records with `id` and `status`: `pass`, `fail`, or `unknown` |
| `missing_evidence` | List of unresolved evidence IDs or descriptions |
| `required_approvals` | List of named outstanding approval requirements |
| `expires_at` | Nonnegative epoch second, or null for unspecified review time |
| `audit` | List of inert provider provenance records; overrides append their own record |
| `authority` | Always `none`, regardless of provider input |

The provider determines ranking order and score meaning. The host preserves them
and never substitutes its own scoring algorithm. Unknown confidence and risk
remain explicit. Stale, missing, unknown, or expired request evidence is appended
to `missing_evidence`; confidence becomes null and an otherwise advisory result
requires review. Failed or unknown gates also require review. Required host
approval adds `host_policy`, even if the provider omits approvals. Provider
approval entries cannot remove this requirement.

At `expires_at <= now`, recommendations and rankings are removed, status becomes
`expired`, and expiry remains inspectable. Scope mismatches, duplicate ranked
IDs, and unknown candidates return `invalid` with no foreign provider content.
Unavailable, expired, and invalid outcomes never carry an actionable ranking.
None of these statuses means that an execution policy approved anything.

## Overrides and replacement

```php
$overridden = $service->override(
    $request, $outcome, 'indexing',
    'The access work is already assigned.',
    $currentEpochSeconds
);
```

Override returns a new record, retains the original provider ranking, records the
authenticated request principal, prior recommendation, and reason, and requires
execution-policy review. It cannot introduce a candidate outside the request or
revive expired, invalid, or unavailable guidance. It does not call the provider
or mutate the original records. Applications can replace a provider by injecting
another implementation and issuing a new request; no provider-state update API
is exposed.

The boundary has no task, budget, goal, agent, command, deployment, persistence,
or workflow dependency and performs no such mutation. It is a contract for
trusted extensions, not a PHP sandbox: application owners must review providers
for their own side effects. Guidance text and audit metadata are untrusted
display data, never executable instructions or proof of policy approval. Any
subsequent action must use the application's existing authorization and execution
owners. No network inference is enabled by installing this extension.

Deterministic fixtures cover ranking, unavailable providers, stale evidence,
expiry, tenant/application/principal mismatch, invalid candidates, gates,
reference isolation, serialization, and overrides without provider calls.
