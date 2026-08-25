<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCapabilityMapValidator;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentRuntimeStateStore;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateStore;
use BlueFission\Arr;
use BlueFission\Data\Storage\Storage;
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

    public function testReplacingADescriptorEvictsEveryCachedRuntimeScope(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $replacement = $this->map('application', 'opus.central', 'central', [], 'opus.central.v2');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());

        $service->registerMap($replacement);
        $service->execute('opus.central', ['intent' => 'status'], new AgentRuntimeContext());

        $this->assertSame(2, $factory->created);
        $this->assertSame(['opus.central', 'opus.central.v2'], $factory->profiles);
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

    public function testRelationshipDenialOverridesLocalToolAllowance(): void
    {
        $root = new AgentCapabilityMap(AgentCapabilityMapValidator::VERSION, 'application', [
            'opus.central' => [
                'mode' => 'central',
                'description' => 'Test agent.',
                'profile' => 'opus.central',
                'tools' => ['command.list'],
                'imports' => [['from' => 'addon.missing', 'tools' => ['missing.run']]],
                'exports' => [],
                'permissions' => [],
                'lifecycle' => ['states' => ['active']],
            ],
        ]);
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);

        $result = $service->start('opus.central', new AgentRuntimeContext());

        $this->assertSame(AgentRuntimeResult::DENIED, $result->status());
        $this->assertSame(['agent_relationship_invalid'], $result->diagnostics());
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

    public function testCancellationCompletionPreservesAConcurrentLifecycleTransition(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $factory->onCancel = function () use ($service): void {
            $service->stop('opus.central', new AgentRuntimeContext(correlationId: 'stop-b'));
        };

        $cancelled = $service->cancel(
            'opus.central',
            new AgentRuntimeContext(correlationId: 'cancel-a')
        );
        $rejected = $service->execute(
            'opus.central',
            ['intent' => 'later'],
            new AgentRuntimeContext(correlationId: 'execute-c')
        );

        $this->assertSame(AgentCompositionService::STOPPED, $cancelled->state());
        $this->assertSame(AgentRuntimeResult::DENIED, $rejected->status());
    }

    public function testReplacementExecutionIsFencedFromConcurrentCancellation(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $initial = $service->execute(
            'opus.central',
            ['intent' => 'initial'],
            new AgentRuntimeContext(correlationId: 'execute-a')
        );
        $states = (new \ReflectionClass($service))->getProperty('states')->getValue($service);
        $otherHost = new AgentCompositionService(
            new AgentCapabilityMapResolver($root),
            $factory,
            $states
        );
        $otherHost->registerMap($root);
        $duringCancellation = null;
        $factory->onCancel = function () use ($otherHost, &$duringCancellation): void {
            $duringCancellation = $otherHost->execute(
                'opus.central',
                ['intent' => 'replacement'],
                new AgentRuntimeContext(correlationId: 'execute-b')
            );
        };

        $cancelled = $service->cancel(
            'opus.central',
            new AgentRuntimeContext(correlationId: 'cancel-a')
        );
        $factory->onCancel = null;
        $replacement = $service->execute(
            'opus.central',
            ['intent' => 'replacement'],
            new AgentRuntimeContext(correlationId: 'execute-c')
        );
        $nextCancellation = $service->cancel(
            'opus.central',
            new AgentRuntimeContext(correlationId: 'cancel-d')
        );

        $this->assertTrue($cancelled->ok());
        $this->assertTrue($cancelled->toArray()['metadata']['cancellation_superseded']);
        $this->assertInstanceOf(AgentRuntimeResult::class, $duringCancellation);
        $this->assertTrue($duringCancellation->ok());
        $this->assertTrue($replacement->ok());
        $this->assertTrue($nextCancellation->ok());
        $this->assertSame(
            $initial->toArray()['metadata']['execution_id'],
            $factory->runtimes[0]->cancelledExecutions[0]
        );
        $this->assertNotSame(
            $factory->runtimes[0]->cancelledExecutions[0],
            $duringCancellation->toArray()['metadata']['execution_id']
        );
        $this->assertSame(
            $replacement->toArray()['metadata']['execution_id'],
            $factory->runtimes[0]->cancelledExecutions[1]
        );
        $this->assertSame(2, $factory->runtimes[0]->calls['cancel']);
    }

    public function testConcurrentCancellationClaimsInvokeTheProviderOnce(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $concurrent = null;
        $factory->onCancel = function () use ($service, &$concurrent): void {
            $concurrent = $service->cancel(
                'opus.central',
                new AgentRuntimeContext(correlationId: 'cancel-b')
            );
        };

        $cancelled = $service->cancel(
            'opus.central',
            new AgentRuntimeContext(correlationId: 'cancel-a')
        );

        $this->assertTrue($cancelled->ok());
        $this->assertInstanceOf(AgentRuntimeResult::class, $concurrent);
        $this->assertSame(AgentRuntimeResult::DENIED, $concurrent->status());
        $this->assertSame(['agent_cancellation_in_progress'], $concurrent->diagnostics());
        $this->assertSame(1, $factory->runtimes[0]->calls['cancel']);
    }

    public function testConcurrentExecutionsAreSerializedPerAgentScope(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $states = (new \ReflectionClass($service))->getProperty('states')->getValue($service);
        $otherFactory = $this->factory();
        $otherHost = new AgentCompositionService(
            new AgentCapabilityMapResolver($root),
            $otherFactory,
            $states
        );
        $otherHost->registerMap($root);
        $overlap = null;
        $factory->onExecute = function () use ($otherHost, &$overlap): void {
            $overlap = $otherHost->execute(
                'opus.central',
                ['intent' => 'overlap'],
                new AgentRuntimeContext(correlationId: 'execute-b')
            );
        };

        $initial = $service->execute(
            'opus.central',
            ['intent' => 'initial'],
            new AgentRuntimeContext(correlationId: 'execute-a')
        );
        $factory->onExecute = null;
        $next = $otherHost->execute(
            'opus.central',
            ['intent' => 'next'],
            new AgentRuntimeContext(correlationId: 'execute-c')
        );

        $this->assertTrue($initial->ok());
        $this->assertInstanceOf(AgentRuntimeResult::class, $overlap);
        $this->assertSame(AgentRuntimeResult::DENIED, $overlap->status());
        $this->assertSame(['agent_execution_in_progress'], $overlap->diagnostics());
        $this->assertTrue($next->ok());
        $this->assertSame(1, $factory->runtimes[0]->calls['execute']);
        $this->assertSame(1, $otherFactory->runtimes[0]->calls['execute']);
    }

    public function testRuntimeCreationFailureDoesNotClearCancellationEvidence(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $service->cancel('opus.central', new AgentRuntimeContext(correlationId: 'cancel-a'));
        $states = (new \ReflectionClass($service))->getProperty('states')->getValue($service);
        $failingFactory = $this->factory();
        $failingFactory->throwOnCreate = true;
        $nextRequest = new AgentCompositionService(
            new AgentCapabilityMapResolver($root),
            $failingFactory,
            $states
        );
        $nextRequest->registerMap($root);

        $failed = $nextRequest->execute(
            'opus.central',
            ['intent' => 'next'],
            new AgentRuntimeContext(correlationId: 'execute-b')
        );
        $cancelledAgain = $service->cancel(
            'opus.central',
            new AgentRuntimeContext(correlationId: 'cancel-c')
        );

        $this->assertSame(AgentRuntimeResult::FAILED, $failed->status());
        $this->assertTrue($cancelledAgain->toArray()['metadata']['idempotent']);
        $this->assertSame(1, $factory->runtimes[0]->calls['cancel']);
    }

    public function testExecutionCompletionPreservesAConcurrentLifecycleTransition(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $factory->onExecute = function () use ($service): void {
            $service->suspend('opus.central', new AgentRuntimeContext(correlationId: 'suspend-b'));
        };

        $executed = $service->execute(
            'opus.central',
            ['intent' => 'next'],
            new AgentRuntimeContext(correlationId: 'execute-a')
        );
        $rejected = $service->execute(
            'opus.central',
            ['intent' => 'later'],
            new AgentRuntimeContext(correlationId: 'execute-c')
        );

        $this->assertSame(AgentCompositionService::SUSPENDED, $executed->state());
        $this->assertSame(AgentRuntimeResult::DENIED, $rejected->status());
        $this->assertSame('execute-c', $rejected->toArray()['metadata']['correlation_id']);
    }

    public function testCompletedStopCannotBeOverwrittenBeforeExecutionStarts(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $states = (new \ReflectionClass($service))->getProperty('states')->getValue($service);
        $nextFactory = $this->factory();
        $nextFactory->onCreate = function () use ($service): void {
            $service->stop('opus.central', new AgentRuntimeContext(correlationId: 'stop-b'));
        };
        $nextRequest = new AgentCompositionService(
            new AgentCapabilityMapResolver($root),
            $nextFactory,
            $states
        );
        $nextRequest->registerMap($root);

        $result = $nextRequest->execute(
            'opus.central',
            ['intent' => 'later'],
            new AgentRuntimeContext(correlationId: 'execute-a')
        );

        $this->assertSame(AgentRuntimeResult::DENIED, $result->status());
        $this->assertSame(AgentCompositionService::STOPPED, $result->state());
        $this->assertSame(['agent_not_running'], $result->diagnostics());
        $this->assertSame(0, $nextFactory->runtimes[0]->calls['execute']);
    }

    public function testLifecycleClaimsOnlyTheStateValidatedByTheCaller(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $states = (new \ReflectionClass($service))->getProperty('states')->getValue($service);
        $reads = 0;
        $states->onGet = function ($store, string $key) use (&$reads): void {
            $reads++;
            if ($reads !== 3) {
                return;
            }

            $store->onGet = null;
            $store->states[$key] = Arr::merge($store->states[$key], [
                'state' => AgentCompositionService::STOPPED,
                'transition_id' => null,
            ]);
        };

        $result = $service->suspend(
            'opus.central',
            new AgentRuntimeContext(correlationId: 'suspend-a')
        );

        $this->assertSame(AgentRuntimeResult::DENIED, $result->status());
        $this->assertSame(AgentCompositionService::STOPPED, $result->state());
        $this->assertSame(0, $factory->runtimes[0]->calls['suspend']);
    }

    public function testExecutionCompletionUsesAnAtomicMetadataMerge(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $states = (new \ReflectionClass($service))->getProperty('states')->getValue($service);
        $states->rejectPut = true;

        $result = $service->execute(
            'opus.central',
            ['intent' => 'next'],
            new AgentRuntimeContext(correlationId: 'execute-a')
        );

        $this->assertTrue($result->ok());
        $this->assertSame(
            AgentRuntimeResult::COMPLETED,
            $states->get('opus.central', null)['status']
        );
    }

    public function testExecutionFailureRetainsTheRequestCorrelationIdentifier(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $factory->throwOnExecute = true;

        $result = $service->execute(
            'opus.central',
            ['intent' => 'fail'],
            new AgentRuntimeContext(correlationId: 'failure-a')
        );

        $this->assertSame(AgentRuntimeResult::FAILED, $result->status());
        $this->assertSame('failure-a', $result->toArray()['metadata']['correlation_id']);
    }

    public function testConcurrentIdenticalLifecycleTransitionsInvokeTheProviderOnce(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $concurrent = null;
        $factory->onStart = function () use ($service, &$concurrent): void {
            $concurrent = $service->start(
                'opus.central',
                new AgentRuntimeContext(correlationId: 'start-b')
            );
        };

        $started = $service->start(
            'opus.central',
            new AgentRuntimeContext(correlationId: 'start-a')
        );

        $this->assertTrue($started->ok());
        $this->assertInstanceOf(AgentRuntimeResult::class, $concurrent);
        $this->assertSame(AgentRuntimeResult::DENIED, $concurrent->status());
        $this->assertSame(['agent_transition_in_progress'], $concurrent->diagnostics());
        $this->assertSame(1, $factory->runtimes[0]->calls['start']);
    }

    public function testAvailabilityProbeFailuresReturnStructuredLifecycleFailures(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $factory->throwOnAvailable = true;
        $service = $this->service($root, $factory);
        $service->registerMap($root);

        $result = $service->start(
            'opus.central',
            new AgentRuntimeContext(correlationId: 'start-a')
        );

        $this->assertSame(AgentRuntimeResult::FAILED, $result->status());
        $this->assertSame(['runtime_availability_failed'], $result->diagnostics());
        $this->assertSame(AgentCompositionService::FAILED, $result->state());
    }

    public function testAbandonedLifecycleClaimsCanBeRecovered(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $states = (new \ReflectionClass($service))->getProperty('states')->getValue($service);
        $states->put('opus.central', null, [
            'state' => AgentCompositionService::REGISTERED,
            'transition_id' => 'abandoned-transition',
        ]);

        $result = $service->start(
            'opus.central',
            new AgentRuntimeContext(correlationId: 'recovery-a')
        );

        $this->assertTrue($result->ok());
        $this->assertSame(AgentCompositionService::RUNNING, $result->state());
        $this->assertSame(1, $factory->runtimes[0]->calls['start']);
    }

    public function testLiveTransitionClaimBlocksIdempotentSuccessWithoutWallClockExpiry(): void
    {
        $root = $this->map('application', 'opus.central', 'central');
        $factory = $this->factory();
        $service = $this->service($root, $factory);
        $service->registerMap($root);
        $service->start('opus.central', new AgentRuntimeContext());
        $states = (new \ReflectionClass($service))->getProperty('states')->getValue($service);
        $operationId = 'long-running-transition';
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opus-agent-operation-' . $operationId . '.lock';
        $handle = fopen($path, 'c+');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $states->put('opus.central', null, Arr::merge(
            $states->get('opus.central', null) ?? [],
            [
                'transition_id' => $operationId,
                'transition_action' => 'stop',
                'transition_expires_at' => 0,
            ]
        ));

        try {
            $result = $service->start('opus.central', new AgentRuntimeContext());

            $this->assertSame(AgentRuntimeResult::DENIED, $result->status());
            $this->assertSame(['agent_transition_in_progress'], $result->diagnostics());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($path);
        }
    }

    public function testStorageCompareAndPutUsesTheSharedLockBoundary(): void
    {
        $lock = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opus-agent-state-' . bin2hex(random_bytes(5));
        $storage = new Storage();
        $first = new AgentRuntimeStateStore($storage, $lock);
        $second = new AgentRuntimeStateStore($storage, $lock);
        $first->put('opus.central', null, ['state' => AgentCompositionService::REGISTERED]);

        $claimed = $first->compareAndPut(
            'opus.central',
            null,
            ['state' => AgentCompositionService::REGISTERED, 'transition_id' => null],
            ['transition_id' => 'claim-a']
        );
        $duplicate = $second->compareAndPut(
            'opus.central',
            null,
            ['state' => AgentCompositionService::REGISTERED, 'transition_id' => null],
            ['transition_id' => 'claim-b']
        );

        $this->assertTrue($claimed);
        $this->assertFalse($duplicate);
        $this->assertSame('claim-a', $second->get('opus.central', null)['transition_id']);
        @unlink($lock);
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
            public mixed $onGet = null;
            public bool $rejectPut = false;

            public function get(string $agentId, ?string $tenantId): ?array
            {
                $key = $this->key($agentId, $tenantId);
                if (is_callable($this->onGet)) {
                    ($this->onGet)($this, $key);
                }

                return $this->states[$key] ?? null;
            }

            public function put(string $agentId, ?string $tenantId, array $state): void
            {
                if ($this->rejectPut) {
                    throw new \RuntimeException('non_atomic_put_rejected');
                }
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
                    $actual = $current[$path] ?? null;
                    if ($actual !== $value) {
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
        };

        return new AgentCompositionService(new AgentCapabilityMapResolver($root), $factory, $states);
    }

    private function factory(): object
    {
        return new class implements IAgentRuntimeFactory {
            public bool $available = true;
            public bool $failNextStart = false;
            public bool $throwOnExecute = false;
            public bool $throwOnCreate = false;
            public bool $throwOnAvailable = false;
            public mixed $onCreate = null;
            public mixed $onStart = null;
            public mixed $onExecute = null;
            public mixed $onCancel = null;
            public int $created = 0;
            public array $runtimes = [];
            public array $profiles = [];

            public function available(AgentDescriptor $descriptor, AgentRuntimeContext $context): bool
            {
                if ($this->throwOnAvailable) {
                    throw new \RuntimeException('runtime_availability_failed');
                }
                return $this->available;
            }

            public function create(AgentDescriptor $descriptor, AgentRuntimeContext $context): IAgentRuntime
            {
                if ($this->throwOnCreate) {
                    throw new \RuntimeException('runtime_create_failed');
                }
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
                    public array $cancelledExecutions = [];

                    public function __construct(private object $factory)
                    {
                    }

                    public function start(AgentRuntimeContext $context): AgentRuntimeResult
                    {
                        $this->calls['start']++;
                        $this->contexts[] = $context;
                        if (is_callable($this->factory->onStart)) {
                            ($this->factory->onStart)();
                        }
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

                    public function cancel(?string $executionId, AgentRuntimeContext $context): AgentRuntimeResult
                    {
                        $this->calls['cancel']++;
                        $this->contexts[] = $context;
                        $this->cancelledExecutions[] = $executionId;
                        if (is_callable($this->factory->onCancel)) {
                            ($this->factory->onCancel)();
                        }
                        return AgentRuntimeResult::completed('cancel', '', null, 'running');
                    }

                    public function execute(
                        string $executionId,
                        array $task,
                        AgentRuntimeContext $context
                    ): AgentRuntimeResult
                    {
                        $this->calls['execute']++;
                        $this->contexts[] = $context;
                        if (is_callable($this->factory->onExecute)) {
                            ($this->factory->onExecute)();
                        }
                        if ($this->factory->throwOnExecute) {
                            throw new \RuntimeException('execution_failed');
                        }
                        return AgentRuntimeResult::completed('execute', '', null, 'running', $task);
                    }
                };
                $this->created++;
                $this->profiles[] = $descriptor->profile();
                $this->runtimes[] = $runtime;
                if (is_callable($this->onCreate)) {
                    ($this->onCreate)();
                }

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
        array $permissions = [],
        ?string $profile = null
    ): AgentCapabilityMap {
        return new AgentCapabilityMap(AgentCapabilityMapValidator::VERSION, $owner, [
            $id => [
                'mode' => $mode,
                'description' => 'Test agent.',
                'profile' => $profile ?? $id,
                'tools' => ['command.list'],
                'imports' => [],
                'exports' => [],
                'permissions' => $permissions,
                'lifecycle' => ['states' => ['active']],
            ],
        ]);
    }
}
