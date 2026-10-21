<?php

declare(strict_types=1);

namespace AddOns\Tributary\Tests;

use AddOns\Tributary\Business\TaskCommandProcessor;
use AddOns\Tributary\Business\TaskService;
use AddOns\Tributary\Domain\Project;
use App\Business\Services\AddOnContractValidator;
use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentDelegationService;
use App\Business\Services\AgentRuntimeStateStore;
use App\Business\Services\ApplicationAgentRuntimeStateStore;
use App\Domain\Agents\AgentApplicationScope;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateSynchronizer;
use BlueFission\Data\Storage\Storage;
use BlueFission\DevElation;
use BlueFission\Behavioral\Behaviors\Action;
use BlueFission\Behavioral\Behaviors\Behavior;
use BlueFission\Services\Application;
use BlueFission\Synematic\Workflow\Pipeline;
use BlueFission\Wise\Cmd\CommandProcessor;
use BlueFission\Wise\Cmd\CommandRequest;
use BlueFission\Wise\Cmd\CommandResult;
use PHPUnit\Framework\TestCase;

final class TaskServiceTest extends TestCase
{
    private array $executions = [];
    private bool $available = true;
    private bool $fail = false;
    private ?AgentRuntimeContext $current = null;
    private int $reads = 0;
    private int $probes = 0;

    public function testCanonicalPackageAndPassiveFactory(): void
    {
        $root = dirname(__DIR__);
        $result = (new AddOnContractValidator())->validate($root);
        self::assertTrue($result['valid'], json_encode($result['errors']));
        $factory = require $root . '/main.php';
        self::assertIsCallable($factory);
        self::assertSame('tributary', $factory()->contributions()['agents']['owner']);
    }

    public function testDiscoveryAndParsingDoNotProbeOrRunAgents(): void
    {
        [$processor] = $this->fixture(start: false);
        self::assertSame(CommandResult::PARSED, $processor->process(CommandRequest::parse('do tributary-task "operations" "report"'))->status());
        self::assertSame(0, $this->reads);
        self::assertContains('do tributary-task', $processor->availableCommands());
        $result = $processor->process('get tributary-task "operations"');
        self::assertSame(CommandResult::COMPLETED, $result->status());
        self::assertSame(['report'], $result->output()['output']['tasks']);
        self::assertSame(0, $this->probes);
        self::assertSame([], $this->executions);
    }

    /** @dataProvider teamSizes */
    public function testNativeWiseAndAutomataRunBoundedAssignment(int $workers): void
    {
        [$processor] = $this->fixture(workers: $workers);
        $result = $processor->process('do tributary-task "operations" "report"');
        self::assertSame(CommandResult::COMPLETED, $result->status(), json_encode($result->toArray()));
        self::assertCount($workers + 1, $this->executions);
        self::assertCount($workers + 1, $result->output()['worker_results']);
        foreach ($this->executions as $execution) {
            self::assertSame(['report.read'], $execution['context']['capabilities']);
            self::assertSame('operator', $execution['context']['actor']['id']);
            self::assertSame('tenant', $execution['context']['tenant_id']);
            self::assertSame('app', $execution['context']['application_id']);
            self::assertSame('correlation', $execution['context']['correlation_id']);
            self::assertSame('operations', $execution['task']['input']['assignment']['project_id']);
        }
        self::assertSame(['report.read'], $result->output()['assignment']['capabilities']);
        self::assertArrayNotHasKey('input', $result->output()['assignment']);
    }

    public static function teamSizes(): array { return [[1], [2]]; }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testNativeApplicationRegistrationKeepsInjectedWorkflowAndFreshCommandAuthority(): void
    {
        // Host-suite child processes bootstrap Opus, not this optional source package.
        require_once __DIR__ . '/bootstrap.php';
        [, $composition] = $this->fixture();
        $workflows = 0;
        $application = new Application(['name' => 'tributary-registration']);
        $application->bindArgs([
            'agents' => $composition,
            'projects' => [$this->project()],
            'delegation' => new AgentDelegationService($composition),
            'workflowFactory' => function () use (&$workflows): Pipeline {
                ++$workflows;
                return new Pipeline(0);
            },
        ], TaskService::class);
        $service = $application->resolve(TaskService::class);
        $storage = new Storage();
        (new \ReflectionProperty(Storage::class, '_source'))->setValue($storage, []);
        $commands = new TaskCommandProcessor(new CommandProcessor($storage), $service, fn () => $this->current);
        $application->delegate('tributary.tasks', $service);
        $results = [];
        $receivers = [];
        $application->register('tributary.tasks', new Action('DoTributaryTask'),
            function (Behavior $behavior, array $arguments) use ($commands, &$results, &$receivers): void {
                $receivers[] = $this;
                $results[] = $commands->process($arguments[0]);
            });

        $application->perform('DoTributaryTask', 'do tributary-task "operations" "report"');
        $this->current = $this->context(actor: 'viewer');
        $application->perform('DoTributaryTask', 'do tributary-task "operations" "report"');

        self::assertSame([$service, $service], $receivers);
        self::assertSame(CommandResult::COMPLETED, $results[0]->status());
        self::assertSame(CommandResult::FAILED, $results[1]->status());
        self::assertNull($results[1]->output());
        self::assertSame(1, $workflows);
        self::assertCount(2, $this->executions);
    }

    public function testNativeWorkflowIsFreshAndDoesNotExposeAssignmentData(): void
    {
        $pipelines = [];
        $observations = [];
        [$processor] = $this->fixture(workflowFactory: function () use (&$pipelines, &$observations): Pipeline {
            $pipeline = new Pipeline(0);
            $pipeline->on('after', function ($step, $value) use (&$observations): void {
                $observations[] = [$step, $value];
            });
            $pipelines[] = $pipeline;
            return $pipeline;
        });
        for ($i = 0; $i < 2; ++$i) {
            self::assertSame(CommandResult::COMPLETED, $processor->process('do tributary-task "operations" "report"')->status());
        }
        self::assertCount(4, $this->executions);
        self::assertCount(2, $pipelines);
        self::assertNotSame($pipelines[0], $pipelines[1]);
        self::assertSame([
            ['delegate', 'delegated'], ['observe', 'observed'],
            ['delegate', 'delegated'], ['observe', 'observed'],
        ], $observations);
        foreach ($pipelines as $pipeline) {
            self::assertSame(['delegate', 'observe'], array_column($pipeline->telemetry(), 'step'));
            self::assertSame([1, 1], array_column($pipeline->telemetry(), 'attempts'));
            self::assertSame([[], []], array_column($pipeline->telemetry(), 'context'));
        }
    }

    public function testWorkflowFailureBeforeDelegationDoesNotStartAgents(): void
    {
        [$processor] = $this->fixture(workflowFactory: function (): Pipeline {
            $pipeline = new Pipeline(0);
            $pipeline->on('before', fn () => throw new \RuntimeException('private workflow failure'));
            return $pipeline;
        });
        $result = $processor->process('do tributary-task "operations" "report"');
        self::assertSame(CommandResult::FAILED, $result->status());
        self::assertSame([], $this->executions);
        self::assertFalse($result->output()['operation_started']);
        self::assertSame('none', $result->output()['effects']);
    }

    public function testWorkflowFailureAfterDelegationPreservesResultWithoutReplay(): void
    {
        [$processor] = $this->fixture(workflowFactory: function (): Pipeline {
            $pipeline = new Pipeline(0);
            $pipeline->on('after', fn () => throw new \RuntimeException('private workflow failure'));
            return $pipeline;
        });
        $result = $processor->process('do tributary-task "operations" "report"');
        self::assertSame(CommandResult::COMPLETED, $result->status());
        self::assertCount(2, $this->executions);
        self::assertCount(2, $result->output()['worker_results']);
        self::assertSame(['status' => 'failed', 'diagnostics' => ['tributary_workflow_failed']], $result->output()['workflow']);
    }

    public function testReusedWorkflowIsRejectedBeforeAnotherExecution(): void
    {
        $pipeline = new Pipeline(0);
        [$processor] = $this->fixture(workflowFactory: fn (): Pipeline => $pipeline);
        self::assertSame(CommandResult::COMPLETED, $processor->process('do tributary-task "operations" "report"')->status());
        self::assertSame(CommandResult::FAILED, $processor->process('do tributary-task "operations" "report"')->status());
        self::assertCount(2, $this->executions);
    }

    public function testStructuredAndTextCommandsHaveSameAuthorityAndResult(): void
    {
        [$processor] = $this->fixture();
        $text = $processor->process('get tributary-task "operations"');
        $structured = $processor->process(['verb' => 'get', 'resources' => ['tributary-task'], 'args' => ['operations']]);
        self::assertSame($text->toArray(), $structured->toArray());
    }

    /** @dataProvider deniedScopes */
    public function testScopeAndRoleDenialCannotBeOverriddenByRequest(string $dimension): void
    {
        [$processor] = $this->fixture(start: false);
        $this->current = $this->context(
            actor: $dimension === 'actor' ? 'stranger' : ($dimension === 'role' ? 'viewer' : 'operator'),
            tenant: $dimension === 'tenant' ? 'foreign' : 'tenant',
            application: $dimension === 'application' ? 'foreign' : 'app',
            capabilities: $dimension === 'capability' ? ['tributary.read', 'report.read'] : null
        );
        $project = $dimension === 'project' ? 'missing' : 'operations';
        $result = $processor->process(new CommandRequest('do tributary-task "' . $project . '" "report"', context: [
            'actor' => ['id' => 'operator'], 'tenant_id' => 'tenant', 'application_id' => 'app',
            'capabilities' => ['tributary.delegate', 'report.read'],
        ]));
        self::assertSame(['tributary_access_denied'], $result->diagnostics());
        self::assertNull($result->output());
        self::assertSame([], $this->executions);
        self::assertSame(0, $this->probes);
    }

    public static function deniedScopes(): array
    {
        return [['actor'], ['role'], ['tenant'], ['application'], ['project'], ['capability']];
    }

    public function testRequiredToolGrantCannotBeInvented(): void
    {
        [$processor] = $this->fixture();
        $this->current = $this->context(capabilities: ['tributary.read', 'tributary.delegate']);
        self::assertSame(['tributary_access_denied'], $processor->process('do tributary-task "operations" "report"')->diagnostics());
        self::assertSame([], $this->executions);
    }

    public function testRuntimeUnavailabilityDoesNotFallBackOrRetry(): void
    {
        [$processor] = $this->fixture();
        $this->available = false;
        self::assertSame(CommandResult::FAILED, $processor->process('do tributary-task "operations" "report"')->status());
        self::assertSame([], $this->executions);
    }

    public function testWorkerFailurePreservesStructuredResultAndDoesNotRetry(): void
    {
        [$processor] = $this->fixture();
        $this->fail = true;
        $result = $processor->process('do tributary-task "operations" "report"');
        self::assertSame(CommandResult::FAILED, $result->status());
        self::assertCount(2, $this->executions);
        self::assertSame(['fixture_failure'], $result->output()['worker_results'][1]['metadata']['diagnostics']);
    }

    public function testStoppedRuntimeIsNotImplicitlyRestarted(): void
    {
        [$processor, $composition] = $this->fixture();
        self::assertTrue($composition->stop('addon.worker1', $this->current)->ok());
        self::assertSame(CommandResult::FAILED, $processor->process('do tributary-task "operations" "report"')->status());
        self::assertCount(1, $this->executions);
    }

    public function testUnsupportedRetryNeverDispatches(): void
    {
        [$processor] = $this->fixture();
        self::assertSame(CommandResult::INVALID, $processor->process([
            'verb' => 'do', 'resources' => ['tributary-task'], 'args' => ['retry', 'operations', 'report'],
        ])->status());
        self::assertSame([], $this->executions);
    }

    public function testFilterAndObserverCannotReenterAndObservationIsBounded(): void
    {
        [$processor, , $service] = $this->fixture();
        $reflection = new \ReflectionClass(DevElation::class);
        $saved = [];
        foreach (['_isActive', '_actions', '_filters'] as $name) { $saved[$name] = $reflection->getProperty($name)->getValue(); }
        $events = [];
        try {
            DevElation::up();
            DevElation::filter('tributary.task.input', function (array $input) use ($service): array {
                self::assertSame('denied', $service->run('operations', 'report', $this->current)['status']);
                return ['message' => 'changed data'];
            });
            DevElation::action('tributary.task.result', function (array $event) use ($service, &$events): void {
                $events[] = $event;
                self::assertSame('denied', $service->run('operations', 'report', $this->current)['status']);
                throw new \RuntimeException('private observer error');
            });
            self::assertSame(CommandResult::COMPLETED, $processor->process('do tributary-task "operations" "report"')->status());
            self::assertSame([['version' => 1, 'status' => 'completed']], $events);
            self::assertCount(2, $this->executions);
            self::assertSame(['message' => 'changed data'], $this->executions[1]['task']['input']['input']);
        } finally {
            foreach ($saved as $name => $value) { $reflection->getProperty($name)->setValue(null, $value); }
        }
        self::assertSame(CommandResult::COMPLETED, $processor->process('do tributary-task "operations" "report"')->status());
    }

    /** @dataProvider invalidRecords */
    public function testInvalidPortableRecordsFailBeforeRuntime(string $case): void
    {
        $record = $this->project()->toArray();
        if ($case === 'role') { $record['members']['operator'] = ['missing']; }
        if ($case === 'target') { $record['tasks']['report']['targets'] = ['missing']; }
        if ($case === 'object') { $record['tasks']['report']['input'] = ['bad' => new \stdClass()]; }
        if ($case === 'transitive') { $record['tasks']['report']['capabilities'] = ['tributary.delegate']; }
        if ($case === 'runtime') { $record['tasks']['report']['capabilities'] = ['opus.runtime.start']; }
        $this->expectException(\InvalidArgumentException::class);
        new Project($record['id'], $record['application_id'], $record['tenant_id'],
            $record['profiles'], $record['roles'], $record['members'], $record['tasks']);
    }

    public static function invalidRecords(): array { return [['role'], ['target'], ['object'], ['transitive'], ['runtime']]; }

    public function testProjectSnapshotCannotBeChangedThroughNestedCallerReferences(): void
    {
        $value = 'report';
        $record = $this->project()->toArray();
        $record['tasks']['report']['input']['message'] = &$value;
        $project = new Project($record['id'], $record['application_id'], $record['tenant_id'],
            $record['profiles'], $record['roles'], $record['members'], $record['tasks']);
        $value = 'changed';
        self::assertSame('report', $project->tasks['report']['input']['message']);
    }

    public function testMissingCorrelationAndRevokedMembershipNeverDispatch(): void
    {
        [$processor, , $service] = $this->fixture();
        $context = new AgentRuntimeContext('tenant', ['id' => 'operator'], capabilities: $this->current->capabilities(),
            applicationScope: new AgentApplicationScope('app'));
        self::assertSame('denied', $service->run('operations', 'report', $context)['status']);
        self::assertSame(CommandResult::COMPLETED, $processor->process('get tributary-task "operations"')->status());
        $this->current = $this->context(actor: 'removed');
        self::assertSame(['tributary_access_denied'], $processor->process('do tributary-task "operations" "report"')->diagnostics());
        self::assertSame([], $this->executions);
    }

    public function testCompositionKeepsItsOwnScopeBoundary(): void
    {
        [, , $service] = $this->fixture();
        self::assertSame('denied', $service->run('operations', 'report', $this->context(application: 'other'))['status']);
        self::assertSame([], $this->executions);
    }

    public function testProfileDriftIsDeniedBeforeAnyDispatch(): void
    {
        [, $composition] = $this->fixture();
        $record = $this->project()->toArray();
        $record['profiles']['worker1']['profile'] = 'unreviewed.profile';
        $project = new Project($record['id'], $record['application_id'], $record['tenant_id'],
            $record['profiles'], $record['roles'], $record['members'], $record['tasks']);
        $service = new TaskService($composition, [$project], new AgentDelegationService($composition));
        self::assertSame('denied', $service->run('operations', 'report', $this->current)['status']);
        self::assertSame([], $this->executions);
    }

    private function fixture(int $workers = 1, bool $start = true, ?\Closure $workflowFactory = null): array
    {
        $this->current = $this->context();
        $principal = new AgentCapabilityMap(1, 'application', ['principal' => [
            'mode' => 'central', 'profile' => 'principal', 'tools' => [], 'imports' => [], 'permissions' => ['report.read'],
        ]]);
        $factory = $this->createMock(IAgentRuntimeFactory::class);
        $factory->method('available')->willReturnCallback(function (): bool { ++$this->probes; return $this->available; });
        $factory->method('create')->willReturnCallback(function (AgentDescriptor $descriptor): IAgentRuntime {
            $id = $descriptor->id();
            $runtime = $this->createMock(IAgentRuntime::class);
            $runtime->method('start')->willReturn(AgentRuntimeResult::completed('start', $id, 'tenant', 'running'));
            $runtime->method('stop')->willReturn(AgentRuntimeResult::completed('stop', $id, 'tenant', 'stopped'));
            $runtime->method('execute')->willReturnCallback(function ($execution, $task, $context) use ($id): AgentRuntimeResult {
                $this->executions[] = ['agent' => $id, 'task' => $task, 'context' => $context->toArray()];
                if ($id !== 'principal' && $this->fail) { return AgentRuntimeResult::failed('execute', $id, 'tenant', 'running', 'fixture_failure'); }
                return AgentRuntimeResult::completed('execute', $id, 'tenant', 'running',
                    $id === 'principal' ? ['workers' => $task['available_specialists']] : ['message' => 'deterministic report']);
            });
            return $runtime;
        });
        $lock = $this->createMock(IAgentRuntimeStateSynchronizer::class);
        $lock->method('synchronized')->willReturnCallback(fn ($scope, $operation) => $operation());
        $states = new ApplicationAgentRuntimeStateStore(new AgentApplicationScope('app'), new AgentRuntimeStateStore(new Storage(), $lock));
        $composition = new AgentCompositionService(new AgentCapabilityMapResolver($principal), $factory, $states);
        $composition->registerMap($principal);
        $ids = ['principal'];
        for ($i = 1; $i <= $workers; ++$i) {
            $id = 'addon.worker' . $i;
            $ids[] = $id;
            $composition->registerMap(new AgentCapabilityMap(1, 'worker' . $i, [$id => [
                'mode' => 'specialist', 'profile' => 'worker' . $i, 'tools' => [], 'imports' => [],
                'permissions' => ['report.read'], 'lifecycle' => ['states' => ['active']],
            ]]));
        }
        if ($start) { foreach ($ids as $id) { self::assertTrue($composition->start($id, $this->current)->ok()); } }
        $service = new TaskService($composition, [$this->project($workers)], new AgentDelegationService($composition), $workflowFactory);
        $storage = new Storage();
        (new \ReflectionProperty(Storage::class, '_source'))->setValue($storage, []);
        $processor = new TaskCommandProcessor(new CommandProcessor($storage), $service, function (): ?AgentRuntimeContext {
            ++$this->reads;
            return $this->current;
        });
        return [$processor, $composition, $service];
    }

    private function context(string $actor = 'operator', string $tenant = 'tenant', string $application = 'app', ?array $capabilities = null): AgentRuntimeContext
    {
        return new AgentRuntimeContext($tenant, ['id' => $actor], ['worker1', 'worker2'],
            ['worker1' => 'active', 'worker2' => 'active'],
            $capabilities ?? ['tributary.read', 'tributary.delegate', 'report.read', 'opus.runtime.start'],
            'correlation', new AgentApplicationScope($application));
    }

    private function project(int $workers = 1): Project
    {
        $profiles = ['coordinator' => ['agent_id' => 'principal', 'profile' => 'principal']];
        $targets = [];
        for ($i = 1; $i <= $workers; ++$i) {
            $targets[] = 'worker' . $i;
            $profiles['worker' . $i] = ['agent_id' => 'addon.worker' . $i, 'profile' => 'worker' . $i];
        }
        return new Project('operations', 'app', 'tenant', $profiles,
            ['operator' => ['tributary.read', 'tributary.delegate', 'report.read'], 'reader' => ['tributary.read']],
            ['operator' => ['operator'], 'viewer' => ['reader']],
            ['report' => ['source' => 'coordinator', 'targets' => $targets, 'capabilities' => ['report.read'], 'input' => ['message' => 'report']]]);
    }
}
