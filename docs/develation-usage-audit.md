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
Data/Service, behaviors, hooks, or fluent values has been audited. The module
inventory below expands passive source coverage; runtime profiling and complete
reusable-helper gap analysis remain open under #151. Existing contract tests cover
the listed runtime boundaries; the audit does not change application behavior.

## Reproducible module evidence

Run `php -n bin/audit-develation-usage.php` for the complete JSON inventory or
add `--summary` for module totals. The command requires PHP's tokenizer, but no
Composer autoloader, database, provider, application bootstrap, or network access.
The [module snapshot](develation-module-inventory.md) covers all 280 PHP files in
the explicitly scoped `app`, `common`, and `mapping` directories. Tests, tools,
entrypoint scripts, templates, installed dependencies and generated assets are
outside this scope; absence from the inventory is not an audit of those areas.

Each file has a source-byte SHA-256, relative path and line-addressed references.
Names distinguish Val/Str/Arr/Num/Flag/Date/Obj, Data, Services, Behavioral classes
(including Event/State/Action references), and DevElation hooks. Named
class/interface/trait/enum/function declarations support a naming review without
automatically renaming public contracts. Static-root fluent chains retain their
method order. Instance behavior-method candidates are listed separately because
the scanner does not infer the receiver's type.

`complete: true` means every scoped PHP file was read and parsed. It is not a
compatibility, runtime coverage, ownership, or policy verdict. Imports count as
source references even when unused. Dynamic calls, instance receiver types,
return-type/compound-type references and parenthesized fluent receivers are not
fully resolved.
Missing or unreadable directories/files, links and parse failures produce a
nonzero exit and structured errors. Parser diagnostics and source contents are
not copied into the report. Files are not executed, included or autoloaded.

The tokenizer and traversal use native PHP at this dependency-free tooling
boundary, like the existing Composer audits. Application code still follows the
DevElation helper gate. A scan is a view of files read during that run, not an
atomic filesystem snapshot; use a settled checkout when comparing hashes.

## Representative helper ownership comparisons

The following comparisons use the installed v1.3.43 source pin above. They are
retention decisions for these exact boundaries, not blanket helper exemptions.
No application refactor or dependency upgrade accompanies this inventory.

| Host helper and evidence | Owning primitive evidence | Decision |
| --- | --- | --- |
| `AddOnOrganizationService::strings` (`app/Business/Services/AddOnOrganizationService.php:228`) rejects non-lists, excessive length and duplicates | DevElation `src/Arr.php:817` maps while preserving keys; `:976` removes duplicates using string comparison and mutates its value | Retain the host policy wrapper. Replacing rejection with `unique()` would silently accept invalid input. It already composes Arr operations. |
| `AddOnOrganizationService::text` (`:240`) checks type, byte limit, empty policy and control characters while returning the original text | DevElation `src/Str.php:418` returns a boolean match; `:520` trims by mutating the value | Retain policy validation. Returning a trimmed value would change accepted content; the current check uses a separate Str value. |
| `AddOnContractValidator::normalizeTool` (`app/Business/Services/AddOnContractValidator.php:528`) normalizes separators/case and requires a resource/action grammar | DevElation `src/Str.php` owns trim/replace/lower/matches operations, not the Opus command grammar | Retain domain validation while using the existing fluent primitives. A general string operation is not a command authorization or grammar contract. |
| `AddOnContractValidator::readJson` (`:543`) returns an empty array for a missing file and records unreadable/non-array decode diagnostics; metadata rules run separately | DevElation `src/Data/FileSystem.php:641` returns nullable file contents; it does not own package diagnostics | Retain the package-validation boundary. Inspect the owning reader's null result before interpreting metadata. |
| `AgentCapabilityMapCatalog::validOwner` and `path` (`app/Business/Services/AgentCapabilityMapCatalog.php`) combine a reserved owner name, identifier grammar and add-on mapping layout | DevElation `src/Str.php` owns matching and append/substring operations | Retain Opus ownership/layout rules. The existing fluent implementation avoids duplicating string mechanics. |
| `BootAddOnManager` boot observation (`app/Business/Services/BootAddOnManager.php`) and `LazyAgentCommandProcessor` runtime observation | DevElation `src/DevElation.php` dispatches action handlers; `src/Behavioral/Dispatches.php:71,104,150` owns behavior registration/dispatch | Keep settled-state and exception policy in the host boundary. Shared callback dispatch does not make distinct authoritative lifecycles interchangeable. |
| `RuntimePathResolver` pre-autoload resolution | Composer-managed DevElation classes are not available until this boundary succeeds | Retain its characterized native boundary; using an installed helper here would create a bootstrap dependency cycle. |

These comparisons identify no new package-owned primitive gap that warrants a
duplicate upstream request. Remaining helpers require their own owner-API and
behavior review. Before any later module refactor, characterize public returns,
errors, side effects, mutation semantics and representative performance with
tests; source reference counts cannot substitute for that evidence.

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
