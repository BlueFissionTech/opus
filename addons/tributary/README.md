# Tributary

An optional Opus add-on for project-scoped, role-bounded task delegation.
See PRD.md for acceptance and remaining release gates, and ARCHITECTURE.md for
interfaces, hook contracts and isolation limits.

## Use with Opus

The source is maintained in `addons/tributary` with the `AddOns\Tributary`
namespace. Its definition and passive
registration factory follow the canonical add-on contract. Package installation
and activation are explicit host operations; merely loading main.php grants no
permissions and creates no runtime. Source presence in `addons/` makes the
definition discoverable; it does not install dependencies, register Composer
autoloading, enroll governors, or activate the add-on.

The package requires a compatible Opus host, Wise and Synematic. The source is tested
against this repository's composer.lock; its dev-main requirement is not an
immutable release guarantee. Pin the reviewed Opus source when distributing a
host. Composer metadata is supplied for future package installation; this sprint
does not change the host lock or publish a package registry release.

After the host has validated its catalog, enrolled its runtimes and resolved
trusted identity, compose the same processor into CommandHost for UI and CLI:

```php
use AddOns\Tributary\Business\TaskCommandProcessor;
use AddOns\Tributary\Business\TaskService;
use AddOns\Tributary\Domain\Project;

$project = new Project('operations', 'app-id', 'tenant-id',
    [
        'coordinator' => ['agent_id' => 'opus.central', 'profile' => 'principal'],
        'reporter' => ['agent_id' => 'addon.reporting', 'profile' => 'reporting.specialist'],
    ],
    ['operator' => ['tributary.read', 'tributary.delegate', 'report.read']],
    ['authenticated-user-id' => ['operator']],
    ['report' => ['source' => 'coordinator', 'targets' => ['reporter'],
        'capabilities' => ['report.read'], 'input' => ['period' => 'current']]]
);
$tasks = new TaskService($composition, [$project], $delegation);
$processor = new TaskCommandProcessor($wiseProcessor, $tasks, $resolveTrustedContext);
```

The injected composition, delegation service, Wise processor and no-argument context resolver belong
to the host. The resolver must consult current authorization for every call and
return an AgentRuntimeContext with application, tenant, actor, correlation and
host-granted capabilities. Neither example IDs nor request metadata are identity.
Resolve AgentDelegationService against the same AgentCompositionService supplied
to TaskService. The optional fourth constructor argument is a no-argument factory
returning a fresh Synematic Pipeline, allowing host-owned workflow observations.
Tributary adds its steps and enforces zero retries. Factories must not reuse a
pipeline. No dependency is installed or runtime activated by this composition.
Wrap the processor with Opus's AgentScopedCommandProcessor when exposed to a
governor so the governor's own tool policy is also enforced.

For native Application integration, bind the constructor arguments with
`bindArgs(..., TaskService::class)`, resolve the service, and delegate that
instance with `delegate('tributary.tasks', $tasks)`. Register host actions against
that delegate with `Application::register()` and forward their command input to
the same processor. Registering the resolved instance gives callbacks the service
scope. Every invocation still resolves current identity through the processor;
an action name or an earlier successful call does not grant authority. The package
tests exercise this real registration path and deny execution after a role change.

Commands use the native Wise resource syntax:

- `get tributary-task "operations"` discovers profiles and task IDs without runtime probes.
- `do tributary-task "operations" "report"` executes the fixed assignment.

Task grants are bounded by both membership roles and host grants. Only the task's
required grants reach the coordinator and specialists; Tributary and runtime
administration grants cannot be passed onward. The installed specialist map has
profile references checked against enrolled Opus descriptors before dispatch and
no imports or exports. The host must explicitly wire its command adapter; passive
mapping discovery does not register trusted identities or enable execution.

Denied and missing projects return the same diagnostic. Results preserve the core
delegation status, structured output, worker diagnostics and metadata as data.
There is no automatic retry, worker provisioning, persistence, task cancellation,
provider fallback or external activation. Existing Opus runtime controls remain
separately authorized. Tests use deterministic synthetic runtimes and actual
Wise parsing, Opus composition and Automata orchestration; they do not prove a
live multi-tenant deployment or full Synthetiq profile loading.

## Validation from the Opus source root

```text
php bin/opus-addon.php validate addons/tributary
php vendor/phpunit/phpunit/phpunit --do-not-cache-result --filter TaskServiceTest
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

The host suite includes the package tests. No provider key is required, and the
test bootstrap registers only this package's PSR-4 namespace in the existing
Composer loader. It does not install dependencies or boot an application.
