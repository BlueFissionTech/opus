<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCapabilityMapValidator;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentDelegationService;
use App\Business\Services\ApplicationAgentRuntimeStateStore;
use App\Domain\Agents\AgentApplicationScope;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentDelegationResult;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateStore;
use BlueFission\Arr;
use BlueFission\DevElation;
use PHPUnit\Framework\TestCase;

final class AgentDelegationServiceTest extends TestCase
{
    public function testExplicitEmptySelectionDoesNotExecuteSpecialists(): void
    {
        [$service, $composition, $factory] = $this->service();
        $context = $this->context();
        $this->startAll($composition, $context);
        $factory->outputs['opus.central'] = ['workers' => []];

        $result = $service->delegate('opus.central', ['addon.search', 'addon.billing'], [], $context);

        $this->assertTrue($result->ok());
        $this->assertSame([], $result->output());
        $this->assertCount(1, $factory->executions['opus.central']);
        $this->assertArrayNotHasKey('addon.search', $factory->executions);
        $this->assertArrayNotHasKey('addon.billing', $factory->executions);
        $this->assertCount(1, $result->toArray()['worker_results']);
    }

    /** @dataProvider invalidSelections */
    public function testMalformedSelectionFailsBeforeAnySpecialistRuns(mixed $selection): void
    {
        [$service, $composition, $factory] = $this->service();
        $context = $this->context();
        $this->startAll($composition, $context);
        $factory->outputs['opus.central'] = ['workers' => $selection];

        $result = $service->delegate('opus.central', ['addon.search', 'addon.billing'], [], $context);

        $this->assertFalse($result->ok());
        $this->assertSame(AgentDelegationResult::FAILED, $result->status());
        $this->assertContains('agent_delegation_selection_invalid', $result->diagnostics());
        $this->assertCount(1, $factory->executions['opus.central']);
        $this->assertArrayNotHasKey('addon.search', $factory->executions);
        $this->assertArrayNotHasKey('addon.billing', $factory->executions);
    }

    public static function invalidSelections(): array
    {
        return [
            'null' => [null], 'scalar' => ['addon.search'], 'boolean' => [false],
            'unknown' => [['addon.unknown']], 'mixed' => [['addon.search', 'addon.unknown']],
            'associative' => [['worker' => 'addon.search']], 'non-string' => [[7]],
            'nested' => [[['id' => 'addon.search']]],
        ];
    }

    public function testOmittedSelectionRetainsDefaultParticipants(): void
    {
        [$service, $composition, $factory] = $this->service();
        $context = $this->context();
        $this->startAll($composition, $context);
        $factory->outputs['opus.central'] = ['plan' => 'Use the host-provided participants'];
        $result = $service->delegate('opus.central', ['addon.search', 'addon.billing'], [], $context);
        $this->assertTrue($result->ok());
        $this->assertCount(1, $factory->executions['addon.search']);
        $this->assertCount(1, $factory->executions['addon.billing']);
    }

    public function testDuplicateSelectionsRunEachSpecialistOnceInRequestedOrder(): void
    {
        [$service, $composition, $factory] = $this->service();
        $context = $this->context();
        $this->startAll($composition, $context);
        $factory->outputs['opus.central'] = ['workers' => ['addon.billing', 'addon.search', 'addon.billing']];
        $result = $service->delegate('opus.central', ['addon.search', 'addon.billing'], [], $context);
        $this->assertTrue($result->ok());
        $this->assertSame(['opus.central', 'addon.billing', 'addon.search'], array_keys($factory->executions));
        $this->assertCount(1, $factory->executions['addon.search']);
        $this->assertCount(1, $factory->executions['addon.billing']);
    }

    /** @dataProvider orchestrationFailures */
    public function testOrchestrationExceptionsAreRedactedWithoutReplayingEffects(\Throwable $failure): void
    {
        [$service, $composition, $factory] = $this->service();
        $context = $this->context();
        $this->startAll($composition, $context);
        $factory->outputs['opus.central'] = ['workers' => ['addon.search']];
        $factory->outputs['addon.search'] = ['matches' => 1];
        $this->expectOutputString('');

        $reflection = new \ReflectionClass(DevElation::class);
        $active = $reflection->getProperty('_isActive');
        $actions = $reflection->getProperty('_actions');
        $originalActive = $active->getValue();
        $originalActions = $actions->getValue();
        try {
            DevElation::up();
            DevElation::action(
                'automata.llm.agent.orchestration.completed',
                static function (...$arguments) use ($failure): void {
                    throw $failure;
                }
            );
            $result = $service->delegate('opus.central', ['addon.search'], ['query' => 'bounded'], $context);
        } finally {
            $active->setValue(null, $originalActive);
            $actions->setValue(null, $originalActions);
        }

        $data = $result->toArray();
        $this->assertFalse($result->ok());
        $this->assertSame(AgentDelegationResult::FAILED, $result->status());
        $this->assertSame(['agent_delegation_operation_failed'], $result->diagnostics());
        $this->assertNull($result->output());
        $this->assertSame([], $data['worker_results']);
        $this->assertSame([], $data['conflicts']);
        $this->assertSame('opus.central', $data['coordinator_id']);
        $this->assertSame(['addon.search'], $data['participants']);
        $this->assertSame('tenant-a', $data['metadata']['tenant_id']);
        $this->assertSame('correlation-a', $data['metadata']['correlation_id']);
        $this->assertStringNotContainsString('private-provider-secret', json_encode($data, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $factory->executions['opus.central']);
        $this->assertCount(1, $factory->executions['addon.search']);

        // Completed effects are not rolled back or automatically replayed after an observer fails.
        $recovered = $service->delegate('opus.central', ['addon.search'], ['query' => 'explicit retry'], $context);
        $this->assertTrue($recovered->ok());
        $this->assertCount(2, $factory->executions['opus.central']);
        $this->assertCount(2, $factory->executions['addon.search']);
    }

    public static function orchestrationFailures(): array
    {
        return [
            'exception' => [new \RuntimeException('private-provider-secret: token and endpoint')],
            'error' => [new \Error('private-provider-secret: internal path')],
        ];
    }

    public function testHierarchicalDelegationExecutesOnlyCoordinatorSelectedSpecialists(): void
    {
        [$service, $composition, $factory] = $this->service();
        $context = $this->context();
        $this->startAll($composition, $context);
        $factory->outputs['opus.central'] = ['workers' => ['addon.search']];
        $factory->outputs['addon.search'] = ['matches' => 3];

        $result = $service->delegate(
            'opus.central',
            ['addon.search', 'addon.billing'],
            ['query' => 'open work'],
            $context
        );
        $data = $result->toArray();

        $this->assertTrue($result->ok());
        $this->assertSame(AgentDelegationResult::COMPLETED, $result->status());
        $this->assertSame(['matches' => 3], $result->output()['addon.search']);
        $this->assertArrayNotHasKey('addon.billing', $result->output());
        $this->assertCount(1, $factory->executions['opus.central']);
        $this->assertCount(1, $factory->executions['addon.search']);
        $this->assertArrayNotHasKey('addon.billing', $factory->executions);
        $this->assertSame(
            ['addon.search', 'addon.billing'],
            $factory->executions['opus.central'][0]['task']['available_specialists']
        );
        $this->assertSame('delegated_task', $factory->executions['addon.search'][0]['task']['operation']);
        $this->assertArrayNotHasKey('prior_results', $factory->executions['addon.search'][0]['task']);
        $this->assertSame('tenant-a', $data['metadata']['tenant_id']);
        $this->assertSame('correlation-a', $data['metadata']['correlation_id']);
    }

    public function testDelegationRejectsInvalidParticipantsBeforeProviderExecution(): void
    {
        [$service, $composition, $factory] = $this->service();
        $context = $this->context();
        $this->startAll($composition, $context);

        $result = $service->delegate(
            'opus.central',
            ['opus.central'],
            ['query' => 'invalid'],
            $context
        );

        $this->assertFalse($result->ok());
        $this->assertSame(AgentDelegationResult::DENIED, $result->status());
        $this->assertSame(['specialist_agent_invalid'], $result->diagnostics());
        $this->assertSame([], $factory->executions);
    }

    public function testCoordinatorFailurePreventsSpecialistExecution(): void
    {
        [$service, $composition, $factory] = $this->service();
        $context = $this->context();
        $this->startAll($composition, $context);
        $factory->failures['opus.central'] = 'planning_failed';

        $result = $service->delegate(
            'opus.central',
            ['addon.search', 'addon.billing'],
            ['query' => 'blocked'],
            $context
        );

        $this->assertFalse($result->ok());
        $this->assertSame(AgentDelegationResult::FAILED, $result->status());
        $this->assertContains('planning_failed', $result->diagnostics());
        $this->assertCount(1, $factory->executions['opus.central']);
        $this->assertArrayNotHasKey('addon.search', $factory->executions);
        $this->assertArrayNotHasKey('addon.billing', $factory->executions);
    }

    public function testCapabilityDenialProducesAProviderNeutralPartialResult(): void
    {
        [$service, $composition, $factory] = $this->service(['billing.use']);
        $startup = $this->context(['billing.use']);
        $this->startAll($composition, $startup);
        $factory->outputs['opus.central'] = ['workers' => ['addon.search', 'addon.billing']];
        $factory->outputs['addon.search'] = ['matches' => 1];

        $result = $service->delegate(
            'opus.central',
            ['addon.search', 'addon.billing'],
            ['query' => 'bounded'],
            $this->context()
        );
        $data = $result->toArray();

        $this->assertFalse($result->ok());
        $this->assertSame(AgentDelegationResult::PARTIAL, $result->status());
        $this->assertContains('agent_permission_denied', $result->diagnostics());
        $this->assertSame(['matches' => 1], $result->output()['addon.search']);
        $this->assertNull($result->output()['addon.billing']);
        $this->assertSame('hierarchical', $data['metadata']['pattern']);
    }

    public function testDelegationPreservesApplicationBindingAndRejectsForeignOrAbsentScope(): void
    {
        $scope = new AgentApplicationScope('app-a');
        [$service, $composition, $factory] = $this->service(applicationScope: $scope);
        $context = $this->context(applicationScope: $scope);
        $this->startAll($composition, $context);
        $factory->outputs['opus.central'] = ['workers' => ['addon.search']];
        $factory->outputs['addon.search'] = ['matches' => 1];
        foreach ([null, new AgentApplicationScope('app-b')] as $foreign) {
            $denied = $service->delegate('opus.central', ['addon.search'], ['query' => 'bounded'],
                $this->context(applicationScope: $foreign));
            $this->assertFalse($denied->ok());
            $this->assertContains('agent_application_scope_mismatch', $denied->diagnostics());
            $this->assertSame([], $factory->executions);
        }
        $allowed = $service->delegate('opus.central', ['addon.search'], ['query' => 'bounded'], $context);
        $this->assertTrue($allowed->ok());
        $this->assertSame(['matches' => 1], $allowed->output()['addon.search']);
        $this->assertCount(1, $factory->executions['opus.central']);
        $this->assertCount(1, $factory->executions['addon.search']);
    }

    public function testApplicationScopeDenialPrecedesDelegationDescriptorValidation(): void
    {
        [$service, , $factory] = $this->service(applicationScope: new AgentApplicationScope('app-a'));
        $foreign = $this->context(applicationScope: new AgentApplicationScope('app-b'));
        foreach ([['opus.central', ['addon.search']], ['unknown', ['unknown']], ['opus.central', []]] as [$coordinator, $participants]) {
            $result = $service->delegate($coordinator, $participants, [], $foreign);
            $this->assertSame(AgentDelegationResult::DENIED, $result->status());
            $this->assertSame(['agent_application_scope_mismatch'], $result->diagnostics());
        }
        $this->assertSame([], $factory->executions);
    }

    private function service(array $billingPermissions = [], ?AgentApplicationScope $applicationScope = null): array
    {
        $root = $this->map('application', 'opus.central', 'central');
        $search = $this->map('search', 'addon.search', 'specialist');
        $billing = $this->map('billing', 'addon.billing', 'generated', $billingPermissions);
        $factory = new DelegationRuntimeFactory();
        $states = new DelegationStateStore();
        $composition = new AgentCompositionService(
            new AgentCapabilityMapResolver($root),
            $factory,
            $applicationScope === null ? $states : new ApplicationAgentRuntimeStateStore($applicationScope, $states)
        );
        $composition->registerMap($root);
        $composition->registerMap($search);
        $composition->registerMap($billing);

        return [new AgentDelegationService($composition), $composition, $factory];
    }

    private function startAll(AgentCompositionService $composition, AgentRuntimeContext $context): void
    {
        $this->assertTrue($composition->start('opus.central', $context)->ok());
        $this->assertTrue($composition->start('addon.search', $context)->ok());
        $this->assertTrue($composition->start('addon.billing', $context)->ok());
    }

    private function context(array $capabilities = [], ?AgentApplicationScope $applicationScope = null): AgentRuntimeContext
    {
        return new AgentRuntimeContext(
            tenantId: 'tenant-a',
            actor: ['id' => 'operator-a'],
            activeAddOns: ['search', 'billing'],
            lifecycleStates: ['search' => 'active', 'billing' => 'active'],
            capabilities: $capabilities,
            correlationId: 'correlation-a',
            applicationScope: $applicationScope
        );
    }

    private function map(
        string $owner,
        string $id,
        string $mode,
        array $permissions = []
    ): AgentCapabilityMap {
        return new AgentCapabilityMap(AgentCapabilityMapValidator::VERSION, $owner, [
            $id => [
                'mode' => $mode,
                'description' => 'Delegation test agent.',
                'profile' => $id,
                'tools' => [],
                'imports' => [],
                'exports' => [],
                'permissions' => $permissions,
                'lifecycle' => ['states' => ['active']],
            ],
        ]);
    }
}

final class DelegationRuntimeFactory implements IAgentRuntimeFactory
{
    public array $outputs = [];
    public array $failures = [];
    public array $executions = [];

    public function available(AgentDescriptor $descriptor, AgentRuntimeContext $context): bool
    {
        return true;
    }

    public function create(AgentDescriptor $descriptor, AgentRuntimeContext $context): IAgentRuntime
    {
        return new DelegationRuntime($descriptor->id(), $this);
    }
}

final class DelegationRuntime implements IAgentRuntime
{
    public function __construct(private string $agentId, private DelegationRuntimeFactory $factory)
    {
    }

    public function start(AgentRuntimeContext $context): AgentRuntimeResult
    {
        return AgentRuntimeResult::completed('start', $this->agentId, $context->tenantId(), 'running');
    }

    public function suspend(AgentRuntimeContext $context): AgentRuntimeResult
    {
        return AgentRuntimeResult::completed('suspend', $this->agentId, $context->tenantId(), 'suspended');
    }

    public function resume(AgentRuntimeContext $context): AgentRuntimeResult
    {
        return AgentRuntimeResult::completed('resume', $this->agentId, $context->tenantId(), 'running');
    }

    public function stop(AgentRuntimeContext $context): AgentRuntimeResult
    {
        return AgentRuntimeResult::completed('stop', $this->agentId, $context->tenantId(), 'stopped');
    }

    public function cancel(?string $executionId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return AgentRuntimeResult::completed('cancel', $this->agentId, $context->tenantId(), 'running');
    }

    public function execute(
        string $executionId,
        array $task,
        AgentRuntimeContext $context
    ): AgentRuntimeResult {
        $this->factory->executions[$this->agentId][] = [
            'execution_id' => $executionId,
            'task' => $task,
            'context' => $context->toArray(),
        ];
        if (isset($this->factory->failures[$this->agentId])) {
            return AgentRuntimeResult::failed(
                'execute',
                $this->agentId,
                $context->tenantId(),
                'running',
                $this->factory->failures[$this->agentId]
            );
        }

        return AgentRuntimeResult::completed(
            'execute',
            $this->agentId,
            $context->tenantId(),
            'running',
            $this->factory->outputs[$this->agentId] ?? ['agent_id' => $this->agentId]
        );
    }
}

final class DelegationStateStore implements IAgentRuntimeStateStore
{
    private array $states = [];

    public function synchronized(string $scope, callable $operation): mixed
    {
        return $operation();
    }

    public function get(string $agentId, ?string $tenantId): ?array
    {
        return $this->states[$this->key($agentId, $tenantId)] ?? null;
    }

    public function put(string $agentId, ?string $tenantId, array $state): void
    {
        $this->states[$this->key($agentId, $tenantId)] = $state;
    }

    public function compareAndPut(
        string $agentId,
        ?string $tenantId,
        array $expected,
        array $state
    ): bool {
        $key = $this->key($agentId, $tenantId);
        $current = $this->states[$key] ?? [];
        foreach ($expected as $path => $value) {
            if (Arr::getPath($current, (string) $path) !== $value) {
                return false;
            }
        }
        $this->states[$key] = Arr::merge($current, $state);

        return true;
    }

    public function delete(string $agentId, ?string $tenantId): void
    {
        unset($this->states[$this->key($agentId, $tenantId)]);
    }

    private function key(string $agentId, ?string $tenantId): string
    {
        return ($tenantId === null ? 'scope:application' : 'tenant:' . $tenantId) . '::' . $agentId;
    }
}
