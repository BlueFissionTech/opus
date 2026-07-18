# AGENTS.standards.md - DevElation-Based Coding Standards

This file is portable guidance for DevElation and projects built on or with it.
It describes design standards, not temporary refactor instructions. Local
`AGENTS.md` and repository-specific behavior files may add operational rules,
but these standards should guide code shape, feature design, reviews, issues,
and examples.

## Core Principles

- Treat DevElation primitives and helpers as first-class citizens, not as thin
  aliases for native PHP functions.
- Prefer fluent objects for values that are captured, mutated, validated,
  observed, constrained, or used more than a couple of times.
- Prefer static helpers when a value only needs one hookable operation and an
  object lifecycle would add noise.
- Keep public APIs package-owned and general. Do not shape upstream libraries
  around one downstream package, workflow, or local tool.
- Synthesize downstream needs into reusable capabilities only when they fit the
  package's natural scope and would make sense to another consumer.
- Favor Blue Fission classes, interfaces, behaviors, services, data objects,
  and connection helpers over third-party or raw native code when the local
  library already owns that capability.
- Use native PHP directly inside primitive/helper implementations when it is
  the implementation boundary. Consumers should generally use the helper
  surface instead.
- Keep changes reviewable. Prefer file-level or module-level passes that reduce
  repeated work in one area over tiny one-function churn, unless the risk of a
  wider pass is too high.

## Public Hygiene

- Public docs, issues, examples, PRs, and comments must be sanitary and
  collaborator-facing.
- Do not mention downstream packages as the reason a feature exists.
- Do not mention local workflow tools, local paths, scratch artifacts, or agent
  coordination systems in public project artifacts.
- It is appropriate to mention direct upstream dependencies when they affect
  implementation, limitations, compatibility, or installation.
- When a downstream request reveals a useful capability, describe the general
  user story and package-owned acceptance criteria.

## When To Add A Feature

Add or extend a DevElation feature when most of these are true:

- The capability is within the package's stated domain.
- The API would be useful to more than one consumer or class of consumer.
- The feature can be expressed with a clear, ubiquitous name.
- It improves fluency, hook/filter access, behavior/event integration,
  dependency injection, or testability.
- It reduces repeated local patterns without hiding important semantics.
- It can be implemented with focused tests and docs.
- It preserves backward compatibility or has an explicit migration path.

Do not add a feature when most of these are true:

- It is only a one-off adapter for one downstream package.
- The proposed behavior is speculative and not grounded in this package's
  domain.
- Native PHP is clearer because the code is implementing the primitive/helper
  itself.
- The helper would obscure by-reference behavior, return-value semantics, IO
  side effects, locks, resources, or performance-sensitive boundaries.
- The name or contract is still unclear.
- A subclass, injected dependency, or consumer-side composition is the better
  boundary.

## Primitive Standards

### Val

Use `Val` for generic values that need lifecycle, truthiness, constraints,
snapshots, events, tags, groups, conditional flow, or hookable access.

Prefer:

- `Val::make($value)` when a generic value will be carried through logic.
- `$value->val()` or `$value()` only when raw data is needed at a boundary.
- `Val::is()`, `isNull()`, `isNotNull()`, `isEmpty()`, `isNotEmpty()`, and
  `isValid()` instead of raw `isset`, `is_null`, `empty`, and loose type checks
  in consumer code.
- `constraint()` when assignment needs local guardrails.
- `when(Event::CHANGE, ...)` when value changes should be observable.
- `snapshot()`, `recall()`, `reset()`, and `delta()` when a previous value must
  be compared or restored.
- `if(...)->then(...)->otherwise(...)` when conditional flow belongs with the
  value being evaluated.
- `copy()` and `as($target)` when derived values should remain distinct from
  the original value.
- `slot()` for deliberate extension points that should work for both instance
  and static helper usage.
- `tag()` and `grp()` to index and retrieve values across an application
  lifecycle.

Use raw values instead when the value is immediately passed through unchanged,
or when wrapping would hide a resource, stream, callback, or external API
contract.

### Str

Use `Str` for strings that are normalized, parsed, compared, matched, appended,
split, encrypted, cased, truncated, or otherwise mutated.

Prefer:

- `Str::make($value)` for multi-step string work.
- `trim()`, `lower()`, `upper()`, `capitalize()`, `snake()`, `camel()`,
  `replace()`, `sub()`, `len()` / `size()`, `repeat()`, and `truncate()` over
  repeated raw string functions.
- `match($value, Str::IGNORE_CASE)`, `startsWith()`, `endsWith()`, `has()`, and
  `contains()` for comparisons and substring checks.
- `split()` when the result should become an `Arr`.
- `matches()`, `matchPattern()`, and `splitBy()` for regex-oriented work.
- `encrypt()` only for simple md5/sha-style string hashes; use the Security
  hash helper for fuller hashing needs.

Use static helpers for one-off normalization. Use a `Str` object when the same
string is changed or inspected multiple times.

Do not use `Str` merely to decorate a single literal that is not being carried
or transformed. Do not replace a native function inside `Str` itself unless it
reduces real implementation complexity.

### Arr And Collection

Use `Arr` for array values that need traversal, lookup, path access, mutation,
or conversion. Use `Collection` for collection semantics and object lists where
that class already fits the domain.

Prefer:

- `Arr::make($array)` for multi-step array work.
- `Arr::is()`, `isNotEmpty()`, `size()` / `count()`, `has()`, `contains()`,
  `hasKey()`, `search()`, `keys()`, `values()`, and `getPath()` for inspection.
- `map()`, `filter()`, and `each()` for traversal. `Arr` and `Collection`
  traversal semantics should stay aligned.
- `slice()`, `splice()`, `join()`, `reverse()`, `merge()`, `mergeRecursive()`,
  `append()`, `prepend()`, `shift()`, `unshift()`, and `pop()` for mutation and
  list work.
- `Arr::use()` after a static predicate when the last static value should be
  continued fluently.

Pay attention to return semantics. For example, native `array_splice()` returns
removed elements while `Arr::splice()` mutates and returns the `Arr` object.
When both behaviors are needed, use `Arr::slice()` to capture removed values and
`Arr::splice()` to mutate.

Use raw arrays when implementing `Arr` itself, when a PHP interface requires an
array, or when native by-reference behavior is the actual contract.

### Num

Use `Num` for numeric values that need formatting, precision, validation,
conversion, math, or event-aware mutation.

Prefer:

- `Num::make($value)` for multi-step numeric work.
- `add()` / `plus()`, `subtract()` / `minus()`, `multiply()` / `times()`, and
  `divide()` / `by()` for fluent math.
- `copy()` before derived math when the original value must remain unchanged.
- Passing other `Val` objects directly when a math method supports unwrapping.
- `format()`, `precision()`, `toString()`, `bin()`, `hex()`, `rom()`, `min()`,
  `max()`, `increment()`, `decrement()`, `pow()`, `sqrt()`, `log()`, `exp()`,
  `percentage()`, and `rand()` where they fit.

Use native numeric operations inside hot loops or low-level math helpers when
the object lifecycle would add noise without improving clarity or hooks.

### Flag

Use `Flag` for boolean values that need explicit truthiness, falsiness,
strictness, casting, or fluent conditional behavior.

Prefer `isTruthy()`, `isFalsy()`, `isBooleanStrict()` /
`isBoolStrict()`, `cast()`, `then()`, and `otherwise()` when logic should stay
attached to the boolean value.

Use raw booleans for immediate branch conditions that do not need lifecycle,
events, casting, or later reuse.

### Date

Use `Date` for time, timestamp, date formatting, and date differences in
DevElation-based code.

Prefer Date helpers over raw `date()` or `DateTime` when code benefits from a
uniform package-level API. Use native date/time objects directly only when an
external API requires them or when implementing Date itself.

### Func

Use `Func` for dynamic callable construction, reflection, signatures, argument
expectations, return typing, body assignment, binding, or runtime-generated
callbacks.

Native `fn` closures are still appropriate for short local callbacks. Use
`Func` when the callable has a lifecycle or needs to be inspected, stored,
bound, typed, or configured.

### Obj

Use `Obj` for dynamic structured objects, typed fields, field-level primitives,
runtime methods, behavior-aware models, and package-owned prototypes.

Prefer `Obj`, `Configurable`, `Programmable`, and package interfaces when a
plain array/object would grow field typing, lifecycle events, dynamic methods,
or dependency-injected behavior.

Do not use `Obj` for anonymous one-off data bags that never cross a meaningful
boundary or never need behavior.

## Behavior Standards

DevElation behavior is built around Events, States, and Actions.

- Actions describe active intent such as `DoUpdate`.
- States describe persistent condition such as `IsUpdating`.
- Events describe happenings such as `OnUpdate`.

Prefer `Dispatches`, `Behaves`, `Configurable`, `Programmable`, and
`StateMachine` when objects need event handling, state history, behavior
permissions, dynamic fields, dynamic methods, or state graphs.

Use behavior metadata (`Meta`) when handlers need context beyond the behavior
name.

IPC is opt-in. Adding `IPCDispatches` to a dispatching class should add IPC
handling in addition to local dispatch. Standard dispatch should not imply IPC
by default.

Use async helpers when externally executed or long-running work should not
block. Keep async boundaries explicit and testable.

## DevElation Hooks And Filters

Use `DevElation::filter()` / `apply()` and `action()` / `do()` to expose
intentional customization points. Use `listen()`, `subscribe()`, and
`trigger()` for lightweight event-style integration when a full behavioral
object is unnecessary.

Add hooks and filters when they make runtime extension or policy injection
clear. Do not add hooks everywhere by default; hooks should have stable names,
documented value shape, and tests for important behavior.

## Package Area Preferences

- Connections: use or extend package connection classes for curl, standard IO,
  streams, sockets, and database links.
- Data: use package data objects for schemas, fields, tables, queues, graphs,
  files, directories, logs, and storage sources.
- Net: use package HTTP, request, response, email, URL, JSON, and header helpers
  for network and protocol code.
- Security: use the Security hash helper for robust hashing; use `Str` only for
  simple string hash helpers.
- System: use package machine, environment, process, and command helpers for
  system-level work.
- Utils: use utility helpers for prototyping and small administrative needs,
  but move stable app behavior into stronger package areas when it matures.
- CLI: use CLI helpers for prompts, progress bars, canvases, args, and terminal
  UI.
- Services: use services for routing, delegates, gateways, application kernels,
  and argument propagation.
- HTML and Parsing: use package HTML/template/parser classes for rendering,
  templating, and parser-language features.
- Prototypes: use prototypes when behavioral objects represent entities,
  agents, collectives, domains, or interacting real/abstract phenomena.

## Refactor And Review Rules

- Inspect the surrounding module before editing. Let existing local patterns
  steer the change.
- Prefer broad-enough passes that reduce repeated primitive misuse in a file or
  module. Avoid one-helper churn unless the change is risky.
- Keep PRs bounded by behavior area, not by individual native functions.
- Preserve public return contracts. Instance helpers often return objects for
  fluency; static helpers may unwrap to raw values for convenience.
- Add or update tests whenever helper use changes behavior, return type, event
  behavior, or public API surface.
- Avoid introducing third-party dependencies when the package already owns a
  reasonable capability.
- Avoid changing implementation boundaries merely for visual consistency.
- Document new public helpers in package docs and examples when they are part
  of the intended surface.
- Use exact validation commands in PRs, including focused tests and full-suite
  proof when shared primitives or services are touched.
