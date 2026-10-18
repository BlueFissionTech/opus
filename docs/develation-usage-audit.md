# DevElation usage audit

This is the first bounded audit increment for #151, against the committed
DevElation v1.3.43 reference `1707d69d6e12cb888af16a3dc64f92ab3ca6e2f6`.
It does not authorize or require a dependency update.

## Inspected boundaries

| Module | Existing package integration | Contract to preserve |
| --- | --- | --- |
| `AddOnContractValidator` | `Service`, `Arr`, `Str`, `FileSystem`, `HTTP`, Vibrato validation | Read declarative inputs without executing package PHP; preserve validation diagnostics and path containment |
| `AddOnLifecycleReadinessService` | `Service`, `Arr`, `Str`, `Flag` | Readiness follows actual child outcomes, including partial failures; normalization cannot manufacture success |
| `AgentCommandContextProvider` | `Arr`, `Str`, BlueCore query, DevElation context filter | Host lifecycle authority survives extension filtering; unavailable reads cannot restore old grants |
| `TerminalSessions` | `Arr`, `Str`, `Flag`, `HTTP`, Wise result | Byte limits and strict boolean approval; identity/continuation ownership cannot change through client input |
| `AgentDescriptor` | `Obj`, `Arr`, `Str` | Object lifecycle and agent descriptor fields remain package contracts |
| `CommandPresentation` | `Arr`, `Str`, `Num`, `Flag` | Structured output and exit codes; scalar values are unwrapped at the presentation boundary |
| `RuntimePathResolver` | Dependency-free native path boundary | Bootstrap must resolve Composer before its helpers are available; lexical paths and filesystem identity are distinct |

These are source observations, not a claim that every use of Val, Date,
Data/Service, behaviors, hooks, or fluent values has been audited. Full module
coverage, runtime profiling, and reusable-helper gap analysis remain open under
#151. Existing contract tests cover the listed runtime boundaries; this increment
changes only the audit mechanism.

## Correct the helper-call gate before applying it

`DevElationHelperUsageTest` scans application PHP for the existing configured set
of native value helpers. Its former token walker treated declarations named
`count` or `trim` as native calls, including public interface declarations. It
also ignored PHP 8's fully qualified name tokens, missing `\trim()` and
`\json_decode()`.

The extracted `Tests\Support\NativeHelperCalls` scanner inventories named calls
without executing source. It reports helper name, source line, and policy reason.
It recognizes explicit global names, case-insensitive spelling, comments between
tokens, and first-class callable references. It excludes declarations (including
returns by reference), instance/nullsafe/static methods, constructors, and
explicitly namespaced functions. A declaration does not exempt calls in its body.
The repository gate retains its original forbidden-helper list and unreadable
file failures; it now uses this characterized scanner instead of two duplicated
token walkers.

The broader scan exposes 14 existing qualified native calls in
`RuntimePathResolver`: two `str_ends_with`, two `strtolower`, one `json_decode`,
one `is_array`, four `str_starts_with`, two `explode`, and two `implode` calls.
This class resolves Composer before DevElation is available. The gate explicitly
checks that exact boundary inventory; additions/removals require review. A fresh
`php -n` subprocess proves path resolution with no Composer autoloader or
DevElation class loaded. Other application files receive no exemption. Replacing
these bootstrap calls with Composer-managed helpers would introduce a circular
dependency rather than improve package reuse.

This is a lexical coding-policy check, not a PHP name resolver or security
sandbox. Unqualified calls follow the existing policy even if a namespace could
shadow a builtin. Function aliases, dynamic callables, reflection, and attribute
class-name resolution are not covered. Native tokenizer arrays and string
inspection remain inside this test-only lexical implementation; application
value behavior remains subject to the gate. Do not mechanically rename public
methods or rewrite runtime boundaries to satisfy a false positive.

Run `php vendor/phpunit/phpunit/phpunit --filter NativeHelperCallsTest` for
isolated syntax fixtures and `--filter DevElationHelperUsageTest` for the real
application scan. The fixtures include source that would throw if executed,
qualified calls, method/interface declarations, by-reference declarations,
package methods, comments, strings, and calls nested in method bodies.
