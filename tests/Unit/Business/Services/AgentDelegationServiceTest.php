<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCapabilityMapValidator;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentDelegationService;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentDelegationResult;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateStore;
use BlueFission\Arr;
use PHPUnit\Framework\TestCase;

final class AgentDelegationServiceTest extends TestCase
{
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

    private function service(array $billingPermissions = []): array
    {
        $root = $this->map('application', 'opus.central', 'central');
        $search = $this->map('search', 'addon.search', 'specialist');
        $billing = $this->map('billing', 'addon.billing', 'generated', $billingPermissions);
        $factory = new DelegationRuntimeFactory();
        $composition = new AgentCompositionService(
            new AgentCapabilityMapResolver($root),
            $factory,
            new DelegationStateStore()
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

    private function context(array $capabilities = []): AgentRuntimeContext
    {
        return new AgentRuntimeContext(
            tenantId: 'tenant-a',
            actor: ['id' => 'operator-a'],
            activeAddOns: ['search', 'billing'],
            lifecycleStates: ['search' => 'active', 'billing' => 'active'],
            capabilities: $capabilities,
            correlationId: 'correlation-a'
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
