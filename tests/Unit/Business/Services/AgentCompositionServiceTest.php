<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCapabilityMapValidator;
use App\Business\Services\AgentCompositionService;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateStore;
use PHPUnit\Framework\TestCase;

final class AgentCompositionServiceTest extends TestCase
{
    public function testRegistrationDoesNotConstructAgentsAndMissingAgentsFailClosed(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $empty = new AgentCapabilityMap(AgentCapabilityMapValidator::VERSION, 'legacy', []);

        $registered = $service->registerMap($empty);
        $missing = $service->start('addon.legacy', new AgentRuntimeContext(tenantId: 'tenant-a'));

        $this->assertSame(0, $registered->output()['registered']);
        $this->assertSame(AgentRuntimeResult::DENIED, $missing->status());
        $this->assertSame(['agent_not_registered'], $missing->diagnostics());
        $this->assertSame(0, $factory->created);
    }

    public function testCentralRuntimeLifecycleAndExecutionAreIdempotent(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $context = new AgentRuntimeContext(correlationId: 'correlation-a');

        $started = $service->start('opus.central', $context);
        $startedAgain = $service->start('opus.central', $context);
        $executed = $service->execute('opus.central', ['intent' => 'status'], $context);
        $stopped = $service->stop('opus.central', $context);
        $stoppedAgain = $service->stop('opus.central', $context);

        $this->assertTrue($started->ok());
        $this->assertTrue($startedAgain->toArray()['metadata']['idempotent']);
        $this->assertSame(['intent' => 'status'], $executed->output());
        $this->assertSame('correlation-a', $executed->toArray()['metadata']['correlation_id']);
        $this->assertSame(AgentCompositionService::STOPPED, $stopped->state());
        $this->assertTrue($stoppedAgain->toArray()['metadata']['idempotent']);
        $this->assertSame(1, $factory->created);
        $this->assertSame(1, $factory->runtimes[0]->calls['start']);
        $this->assertSame(1, $factory->runtimes[0]->calls['stop']);
    }

    public function testSpecialistRuntimesAreIsolatedByTenant(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $specialist = $this->map('sample', 'addon.sample', 'specialist');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->registerMap($specialist);

        $first = $service->start('addon.sample', $this->specialistContext('tenant-a'));
        $second = $service->start('addon.sample', $this->specialistContext('tenant-b'));

        $this->assertTrue($first->ok());
        $this->assertTrue($second->ok());
        $this->assertSame('tenant-a', $first->toArray()['tenant_id']);
        $this->assertSame('tenant-b', $second->toArray()['tenant_id']);
        $this->assertSame(2, $factory->created);
    }

    public function testPolicyDenialPreventsRuntimeConstruction(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $specialist = $this->map('sample', 'addon.sample', 'specialist', ['sample.manage']);
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($specialist);

        $missingTenant = $service->start('addon.sample', new AgentRuntimeContext());
        $missingPermission = $service->start('addon.sample', $this->specialistContext('tenant-a'));

        $this->assertSame(['agent_tenant_required'], $missingTenant->diagnostics());
        $this->assertSame(['agent_permission_denied'], $missingPermission->diagnostics());
        $this->assertSame(0, $factory->created);
    }

    public function testUnavailableRuntimeCanBeRetriedWithoutFatalState(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $factory->available = false;
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $context = new AgentRuntimeContext();

        $unavailable = $service->start('opus.central', $context);
        $factory->available = true;
        $started = $service->start('opus.central', $context);

        $this->assertSame(AgentRuntimeResult::UNAVAILABLE, $unavailable->status());
        $this->assertSame(AgentCompositionService::REGISTERED, $unavailable->state());
        $this->assertTrue($started->ok());
        $this->assertSame(1, $factory->created);
    }

    public function testFailedStartCanRecoverAndSuspendResumeCancelRemainBounded(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $factory->failNextStart = true;
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $context = new AgentRuntimeContext();

        $failed = $service->start('opus.central', $context);
        $recovered = $service->start('opus.central', $context);
        $suspended = $service->suspend('opus.central', $context);
        $suspendedAgain = $service->suspend('opus.central', $context);
        $resumed = $service->resume('opus.central', $context);
        $cancelled = $service->cancel('opus.central', $context);

        $this->assertSame(AgentRuntimeResult::FAILED, $failed->status());
        $this->assertTrue($recovered->ok());
        $this->assertSame(AgentCompositionService::SUSPENDED, $suspended->state());
        $this->assertTrue($suspendedAgain->toArray()['metadata']['idempotent']);
        $this->assertSame(AgentCompositionService::RUNNING, $resumed->state());
        $this->assertSame(AgentCompositionService::RUNNING, $cancelled->state());
        $this->assertSame(1, $factory->created);
    }

    public function testCachedRuntimeReceivesTheCurrentOperationContext(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);

        $service->start('opus.central', new AgentRuntimeContext(
            actor: ['id' => 'actor-a'],
            correlationId: 'correlation-a'
        ));
        $service->execute('opus.central', ['intent' => 'status'], new AgentRuntimeContext(
            actor: ['id' => 'actor-b'],
            correlationId: 'correlation-b'
        ));

        $contexts = $factory->runtimes[0]->contexts;
        $this->assertSame('actor-a', $contexts[0]->actor()['id']);
        $this->assertSame('correlation-a', $contexts[0]->correlationId());
        $this->assertSame('actor-b', $contexts[1]->actor()['id']);
        $this->assertSame('correlation-b', $contexts[1]->correlationId());
    }

    public function testSuccessfulCancellationIsPersistedAndDeduplicatedUntilExecutionResumes(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $context = new AgentRuntimeContext(correlationId: 'cancel-a');

        $service->start('opus.central', $context);
        $cancelled = $service->cancel('opus.central', $context);
        $cancelledAgain = $service->cancel('opus.central', $context);
        $service->execute('opus.central', ['intent' => 'next'], new AgentRuntimeContext(correlationId: 'execute-b'));
        $cancelledAfterExecution = $service->cancel(
            'opus.central',
            new AgentRuntimeContext(correlationId: 'cancel-c')
        );

        $this->assertTrue($cancelled->ok());
        $this->assertTrue($cancelledAgain->toArray()['metadata']['idempotent']);
        $this->assertTrue($cancelledAfterExecution->ok());
        $this->assertSame(2, $factory->runtimes[0]->calls['cancel']);
    }

    public function testCancellationIsClearedBeforeAReplacementExecutionStarts(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $service->cancel('opus.central', new AgentRuntimeContext(correlationId: 'cancel-a'));
        $duringExecution = null;
        $factory->onExecute = function () use ($service, &$duringExecution): void {
            $duringExecution = $service->cancel(
                'opus.central',
                new AgentRuntimeContext(correlationId: 'cancel-b')
            );
        };

        $service->execute('opus.central', ['intent' => 'next'], new AgentRuntimeContext(correlationId: 'execute-b'));

        $this->assertInstanceOf(AgentRuntimeResult::class, $duringExecution);
        $this->assertTrue($duringExecution->ok());
        $this->assertSame(2, $factory->runtimes[0]->calls['cancel']);
    }

    public function testLifecycleTransitionsPreserveCancellationDeduplication(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $service->cancel('opus.central', new AgentRuntimeContext(correlationId: 'cancel-a'));
        $service->suspend('opus.central', new AgentRuntimeContext());

        $retried = $service->cancel('opus.central', new AgentRuntimeContext(correlationId: 'cancel-b'));

        $this->assertTrue($retried->toArray()['metadata']['idempotent']);
        $this->assertSame(1, $factory->runtimes[0]->calls['cancel']);
    }

    public function testApplicationScopeDoesNotCollideWithTenantNamedApplication(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);

        $application = $service->start('opus.central', new AgentRuntimeContext());
        $tenant = $service->start('opus.central', new AgentRuntimeContext(tenantId: 'application'));

        $this->assertTrue($application->ok());
        $this->assertTrue($tenant->ok());
        $this->assertNull($application->toArray()['tenant_id']);
        $this->assertSame('application', $tenant->toArray()['tenant_id']);
        $this->assertSame(2, $factory->created);
    }

    private function service(AgentCapabilityMap $root, IAgentRuntimeFactory $factory): AgentCompositionService
    {
        $states = new class implements IAgentRuntimeStateStore {
            public array $states = [];

            public function get(string $agentId, ?string $tenantId): ?array
            {
                return $this->states[$this->key($agentId, $tenantId)] ?? null;
            }

            public function put(string $agentId, ?string $tenantId, array $state): void
            {
                $this->states[$this->key($agentId, $tenantId)] = $state;
            }

            public function delete(string $agentId, ?string $tenantId): void
            {
                unset($this->states[$this->key($agentId, $tenantId)]);
            }

            private function key(string $agentId, ?string $tenantId): string
            {
                return ($tenantId === null ? 'scope:application' : 'tenant:' . $tenantId) . '::' . $agentId;
            }
        };

        return new AgentCompositionService(new AgentCapabilityMapResolver($root), $factory, $states);
    }

    private function factory(): object
    {
        return new class implements IAgentRuntimeFactory {
            public bool $available = true;
            public bool $failNextStart = false;
            public mixed $onExecute = null;
            public int $created = 0;
            public array $runtimes = [];

            public function available(AgentDescriptor $descriptor, AgentRuntimeContext $context): bool
            {
                return $this->available;
            }

            public function create(AgentDescriptor $descriptor, AgentRuntimeContext $context): IAgentRuntime
            {
                $runtime = new class($this) implements IAgentRuntime {
                    public array $calls = [
                        'start' => 0,
                        'suspend' => 0,
                        'resume' => 0,
                        'stop' => 0,
                        'cancel' => 0,
                        'execute' => 0,
                    ];
                    public array $contexts = [];

                    public function __construct(private object $factory)
                    {
                    }

                    public function start(AgentRuntimeContext $context): AgentRuntimeResult
                    {
                        $this->calls['start']++;
                        $this->contexts[] = $context;
                        if ($this->factory->failNextStart) {
                            $this->factory->failNextStart = false;
                            return AgentRuntimeResult::failed('start', '', null, 'failed', 'start_failed');
                        }

                        return AgentRuntimeResult::completed('start', '', null, 'running');
                    }

                    public function suspend(AgentRuntimeContext $context): AgentRuntimeResult
                    {
                        $this->calls['suspend']++;
                        $this->contexts[] = $context;
                        return AgentRuntimeResult::completed('suspend', '', null, 'suspended');
                    }

                    public function resume(AgentRuntimeContext $context): AgentRuntimeResult
                    {
                        $this->calls['resume']++;
                        $this->contexts[] = $context;
                        return AgentRuntimeResult::completed('resume', '', null, 'running');
                    }

                    public function stop(AgentRuntimeContext $context): AgentRuntimeResult
                    {
                        $this->calls['stop']++;
                        $this->contexts[] = $context;
                        return AgentRuntimeResult::completed('stop', '', null, 'stopped');
                    }

                    public function cancel(AgentRuntimeContext $context): AgentRuntimeResult
                    {
                        $this->calls['cancel']++;
                        $this->contexts[] = $context;
                        return AgentRuntimeResult::completed('cancel', '', null, 'running');
                    }

                    public function execute(array $task, AgentRuntimeContext $context): AgentRuntimeResult
                    {
                        $this->calls['execute']++;
                        $this->contexts[] = $context;
                        if (is_callable($this->factory->onExecute)) {
                            ($this->factory->onExecute)();
                        }
                        return AgentRuntimeResult::completed('execute', '', null, 'running', $task);
                    }
                };
                $this->created++;
                $this->runtimes[] = $runtime;

                return $runtime;
            }
        };
    }

    private function specialistContext(string $tenantId): AgentRuntimeContext
    {
        return new AgentRuntimeContext(
            tenantId: $tenantId,
            activeAddOns: ['sample'],
            lifecycleStates: ['sample' => 'active']
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
                'description' => 'Test agent.',
                'profile' => $id,
                'tools' => ['command.list'],
                'imports' => [],
                'exports' => [],
                'permissions' => $permissions,
                'lifecycle' => ['states' => ['active']],
            ],
        ]);
    }
}
