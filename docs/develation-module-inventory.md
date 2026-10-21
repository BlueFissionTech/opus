# Host module source inventory

Reproduce with `php bin/audit-develation-usage.php` (full JSON) or add `--summary`.

This snapshot covers the 280 PHP files under `app`, `common`, and `mapping` at
Opus base `4cfee8958ed34eefa42901cb2dd2ac14887577ec`. The full report records
relative paths, SHA-256 hashes of the actual source bytes, reference lines,
declarations, direct static fluent chains, and unresolved behavior candidates.
Source line endings affect hashes. No application or dependency code is loaded.

Families below mean direct lexical references, including imports. An empty cell
does not prove absence of runtime use: inherited services, variable receivers,
return/compound types, dynamic calls and parenthesized fluent receivers are not
fully resolved.
Behavior candidates match method names such as `dispatch` or `when`; their
receiver ownership remains unresolved. Counts are not execution frequencies,
performance measurements, coding-policy violations or compatibility scores.

| Module | Files | Referenced families | Direct fluent chains | Behavior candidates | Named declarations |
| --- | ---: | --- | ---: | ---: | ---: |
| `app` | 1 |  | 0 | 0 | 6 |
| `app/Business` | 1 | Service | 0 | 0 | 3 |
| `app/Business/Commands` | 1 | Str, Arr, Service | 5 | 0 | 15 |
| `app/Business/Console` | 7 | Str, Arr, Service, Behavior | 9 | 0 | 47 |
| `app/Business/Console/BotMan` | 1 | Str | 0 | 0 | 17 |
| `app/Business/Databases` | 1 | Data | 0 | 0 | 9 |
| `app/Business/Drivers` | 3 |  | 0 | 0 | 7 |
| `app/Business/Gateways` | 2 | Service | 0 | 0 | 4 |
| `app/Business/Http` | 3 | Data, Service | 0 | 0 | 22 |
| `app/Business/Http/Api` | 3 | Val, Flag, Service | 0 | 0 | 10 |
| `app/Business/Http/Api/Admin` | 3 | Arr, Service | 1 | 0 | 20 |
| `app/Business/Managers` | 1 | Service | 0 | 0 | 4 |
| `app/Business/Middleware` | 4 | Str, Arr | 3 | 0 | 25 |
| `app/Business/Preparers` | 1 | Arr | 1 | 0 | 7 |
| `app/Business/Presentation` | 6 | Str, Arr | 3 | 0 | 25 |
| `app/Business/Processors` | 5 | Str, Arr | 4 | 0 | 15 |
| `app/Business/Prompts` | 17 |  | 0 | 0 | 17 |
| `app/Business/Services` | 44 | Val, Str, Arr, Flag, Date, Data, Service, Hooks | 327 | 1 | 481 |
| `app/Business/Skills` | 3 |  | 0 | 0 | 12 |
| `app/Domain/AddOn` | 2 |  | 0 | 0 | 7 |
| `app/Domain/Agents` | 13 | Str, Arr, Flag, Obj | 13 | 0 | 98 |
| `app/Domain/Console` | 1 | Str, Arr, Num, Flag | 4 | 0 | 16 |
| `app/Domain/Guidance` | 4 | Str, Arr | 4 | 0 | 17 |
| `app/Domain/Initiative` | 21 |  | 0 | 0 | 21 |
| `app/Domain/Initiative/Models` | 20 |  | 0 | 0 | 23 |
| `app/Domain/Initiative/Queries` | 42 |  | 0 | 0 | 105 |
| `app/Domain/Initiative/Repositories` | 28 |  | 0 | 0 | 127 |
| `app/Domain/Onboarding` | 2 | Str, Arr, Date | 10 | 0 | 29 |
| `app/Domain/Onboarding/Models` | 1 | Val, Arr | 4 | 0 | 6 |
| `app/Domain/Onboarding/Repositories` | 1 | Val, Str, Arr | 0 | 0 | 8 |
| `app/Domain/User` | 6 |  | 0 | 0 | 7 |
| `app/Domain/User/Models` | 5 | Data | 0 | 0 | 6 |
| `app/Domain/User/Queries` | 5 |  | 0 | 0 | 12 |
| `app/Domain/User/Repositories` | 4 |  | 0 | 0 | 19 |
| `app/Registration` | 2 | Str, Data | 0 | 0 | 18 |
| `common/bootstrap` | 1 |  | 0 | 0 | 0 |
| `common/config` | 4 |  | 0 | 0 | 0 |
| `common/helpers` | 2 |  | 0 | 0 | 3 |
| `mapping` | 9 | Service | 20 | 0 | 0 |

See [the usage audit](develation-usage-audit.md) for helper ownership comparisons
and the remaining runtime characterization work. This inventory is evidence for
review; it does not authorize a dependency change or a mechanical refactor.
