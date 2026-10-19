<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AutomataStrategyRuntimeFactory;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentRuntimeStateStore;
use App\Business\Services\ApplicationAgentRuntimeStateStore;
use App\Business\Services\AgentCapabilityMapResolver;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentApplicationScope;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\IAgentRuntimeStateStore;
use App\Domain\Agents\IAgentRuntimeStateSynchronizer;
use BlueFission\Data\Storage\Storage;
use BlueFission\Automata\DecisionTree\DecisionTree;
use BlueFission\Automata\DecisionTree\DepthFirstTraceMethod;
use BlueFission\Automata\DecisionTree\Node;
use BlueFission\Automata\Intelligence;
use BlueFission\Automata\LLM\Agent\Capability\AutonomyDecision;
use BlueFission\Automata\Strategy\Routing\Adapter\DecisionTreeRouteAdapter;
use BlueFission\Automata\Strategy\Routing\StrategyDefinition;
use BlueFission\Automata\Strategy\Routing\StrategyEligibility;
use BlueFission\Automata\Strategy\Routing\StrategyRouter;
use BlueFission\Automata\Strategy\Routing\StrategyRouteRequest;
use BlueFission\Automata\Strategy\Routing\StrategyUsage;
use PHPUnit\Framework\TestCase;

final class AutomataStrategyRuntimeTest extends TestCase
{
    private int $builds = 0;
    private int $authorizations = 0;
    private int $evaluations = 0;
    private bool $allowed = true;
    private bool $treeThrows = false;
    private array $requests = [];

    public function testLocalProbeDoesNotBuildOrAuthorizeAndMissingProfileIsUnavailable(): void
    {
        $factory = $this->factory();
        $this->assertTrue($factory->available($this->descriptor(), new AgentRuntimeContext('a')));
        $this->assertFalse($factory->available($this->descriptor('missing'), new AgentRuntimeContext('a')));
        $this->assertFalse($factory->available($this->descriptor(), new AgentRuntimeContext()));
        $this->assertSame(0, $this->builds);
        $this->assertSame(0, $this->authorizations);
        $this->assertSame(0, $this->evaluations);
    }

    public function testActualDecisionTreeRunsThroughNativeRouterAndIntelligence(): void
    {
        $context = new AgentRuntimeContext('a', correlationId: 'request-1');
        $runtime = $this->factory()->create($this->descriptor(), $context);
        $this->assertTrue($runtime->start($context)->ok());
        $result = $runtime->execute('execution-1', ['input' => ['score' => 5]], $context);
        $this->assertTrue($result->ok(), json_encode($result->toArray()));
        $this->assertSame(['decision' => 'review'], $result->output()['decision']);
        $this->assertGreaterThan(0, $this->evaluations);
        $this->assertSame(1, $this->builds);
        $this->assertSame(1, $this->authorizations);
        $metadata = $result->toArray()['metadata'];
        $this->assertSame('execution-1', $metadata['execution_id']);
        $this->assertSame('request-1', $metadata['correlation_id']);
        $this->assertSame(1, $metadata['usage']['invocations']);
        $this->assertSame(['id' => 'tree', 'version' => '1', 'mode' => 'deterministic'], $metadata['selected_strategy']);
        $this->assertSame('adaptive', $this->requests[0]['selection_policy']);
    }

    public function testTaskCannotReplaceAuthorityCandidatesModesOrCorrelation(): void
    {
        $context = new AgentRuntimeContext('a', correlationId: 'host');
        $runtime = $this->factory()->create($this->descriptor(), $context);
        $result = $runtime->execute('host-execution', [
            'input' => ['score' => 5], 'subject_id' => 'forged', 'allowed' => true,
            'candidates' => [['id' => 'evil', 'version' => '1']],
            'allowed_modes' => ['generative'], 'correlation_id' => 'forged',
            'limits' => ['max_invocations' => 999],
        ], $context);
        $this->assertTrue($result->ok());
        $request = $this->requests[0];
        $this->assertNotSame('forged', $request['subject_id']);
        $this->assertSame([['id' => 'tree', 'version' => '1']], $request['candidates']);
        $this->assertSame(['deterministic'], $request['allowed_modes']);
        $this->assertSame('host', $request['correlation_id']);
        $this->assertSame(['max_invocations' => 1], $request['limits']);
    }

    public function testRevocationIsCheckedAgainOnCachedRuntime(): void
    {
        $context = new AgentRuntimeContext('a');
        $runtime = $this->factory()->create($this->descriptor(), $context);
        $this->assertTrue($runtime->execute('first', ['input' => []], $context)->ok());
        $evaluations = $this->evaluations;
        $this->allowed = false;
        $result = $runtime->execute('second', ['input' => []], $context);
        $this->assertSame('denied', $result->status());
        $this->assertSame($evaluations, $this->evaluations);
        $this->assertSame(2, $this->authorizations);
        $this->assertNull($result->output());
    }

    public function testTenantBindingRejectsCrossScopeBeforeAuthorization(): void
    {
        $factory = $this->factory();
        $a = new AgentRuntimeContext('a');
        $b = new AgentRuntimeContext('b');
        $runtime = $factory->create($this->descriptor(), $a);
        foreach (['start', 'suspend', 'resume', 'stop'] as $action) {
            $this->assertSame('denied', $runtime->{$action}($b)->status());
        }
        $this->assertSame('denied', $runtime->execute('cross', [], $b)->status());
        $this->assertSame(0, $this->authorizations);
        $runtime->execute('a', ['input' => []], $a);
        $factory->create($this->descriptor(), $b)->execute('b', ['input' => []], $b);
        $this->assertNotSame($this->requests[0]['subject_id'], $this->requests[1]['subject_id']);
        $this->assertSame(2, $this->builds);
    }

    public function testSynchronousRuntimeDoesNotClaimItInterruptedWork(): void
    {
        $context = new AgentRuntimeContext('a');
        $runtime = $this->factory()->create($this->descriptor(), $context);
        foreach (['start', 'suspend', 'resume', 'stop'] as $action) {
            $this->assertTrue($runtime->{$action}($context)->ok());
            $this->assertTrue($runtime->{$action}($context)->ok());
        }
        $this->assertSame('unavailable', $runtime->cancel('execution', $context)->status());
        $this->assertSame(0, $this->authorizations);
        $this->assertSame(0, $this->evaluations);
    }

    public function testNativeBudgetDenialDoesNotExecuteTheDecisionTree(): void
    {
        $context = new AgentRuntimeContext('a');
        $runtime = $this->factory(policy: ['limits' => ['max_invocations' => 0]])
            ->create($this->descriptor(), $context);
        $result = $runtime->execute('budget', ['input' => []], $context);
        $this->assertSame('unavailable', $result->status());
        $this->assertSame(0, $this->evaluations);
        $this->assertSame(0, $result->toArray()['metadata']['usage']['invocations']);
    }

    public function testWrongSubjectAndAbsentAuthorizationFailClosed(): void
    {
        $context = new AgentRuntimeContext('a');
        foreach ([null, new AutonomyDecision([
            'allowed' => true, 'subject_id' => 'other-tenant',
            'capability_id' => 'review', 'capability_version' => '1',
        ])] as $decision) {
            $runtime = $this->factory(authorize: fn () => $decision)->create($this->descriptor(), $context);
            $this->assertSame('denied', $runtime->execute('denied', [], $context)->status());
        }
        $this->assertSame(0, $this->evaluations);
    }

    public function testAuthorizerCannotBroadenPolicyByMutatingItsRequest(): void
    {
        $context = new AgentRuntimeContext('a');
        $runtime = $this->factory(authorize: function ($descriptor, $context, $request): AutonomyDecision {
            $decision = new AutonomyDecision([
                'allowed' => true, 'subject_id' => $request->subject_id,
                'capability_id' => $request->capability_id, 'capability_version' => $request->capability_version,
            ]);
            $request->limits = ['max_invocations' => 999];
            return $decision;
        }, policy: ['limits' => ['max_invocations' => 0]])->create($this->descriptor(), $context);
        $this->assertSame('unavailable', $runtime->execute('bounded', [], $context)->status());
        $this->assertSame(0, $this->evaluations);
    }

    public function testPrivateAuthorizationErrorsAreRedactedAndLaterRetryWorks(): void
    {
        $context = new AgentRuntimeContext('a', correlationId: 'visible');
        $throw = true;
        $runtime = $this->factory(authorize: function ($descriptor, $context, $request) use (&$throw): AutonomyDecision {
            if ($throw) {
                throw new \RuntimeException('private-provider-key');
            }
            return new AutonomyDecision(['allowed' => true, 'subject_id' => $request->subject_id,
                'capability_id' => $request->capability_id, 'capability_version' => $request->capability_version]);
        })->create($this->descriptor(), $context);
        $result = $runtime->execute('failure', [], $context);
        $this->assertSame('failed', $result->status());
        $this->assertStringNotContainsString('private-provider-key', serialize($result->toArray()));
        $this->assertSame('visible', $result->toArray()['metadata']['correlation_id']);
        $throw = false;
        $this->assertTrue($runtime->execute('retry', ['input' => []], $context)->ok());
    }

    public function testNativeAdapterExceptionDoesNotLeakItsDiagnostics(): void
    {
        $context = new AgentRuntimeContext('a');
        $runtime = $this->factory()->create($this->descriptor(), $context);
        $this->treeThrows = true;
        $result = $runtime->execute('error', ['input' => []], $context);
        $this->assertSame('failed', $result->status());
        $this->assertNull($result->output());
        $this->assertStringNotContainsString('private-adapter-key', serialize($result->toArray()));
        $this->assertSame(1, $result->toArray()['trace'][0]['attempt_count']);
    }

    public function testReentrantExecutionAndLifecycleAreDeniedDuringAuthorization(): void
    {
        $context = new AgentRuntimeContext('a');
        $runtime = null;
        $nested = [];
        $runtime = $this->factory(authorize: function ($descriptor, $context, $request) use (&$runtime, &$nested): AutonomyDecision {
            $nested[] = $runtime->execute('nested', [], $context)->status();
            $nested[] = $runtime->stop($context)->status();
            return new AutonomyDecision(['allowed' => true, 'subject_id' => $request->subject_id,
                'capability_id' => $request->capability_id, 'capability_version' => $request->capability_version]);
        })->create($this->descriptor(), $context);
        $this->assertTrue($runtime->execute('outer', ['input' => []], $context)->ok());
        $this->assertSame(['denied', 'denied'], $nested);
        $this->assertTrue($runtime->stop($context)->ok());
    }

    public function testCompositionOwnsLifecycleAndEnforcesCapabilitiesBeforeNativeExecution(): void
    {
        $root = new AgentCapabilityMap(1, 'application', ['opus.central' => [
            'mode' => 'central', 'profile' => 'review', 'permissions' => ['review.execute'],
            'tools' => [], 'imports' => [], 'exports' => [],
        ]]);
        $states = new class implements IAgentRuntimeStateStore {
            private array $states = [];
            public function synchronized(string $scope, callable $operation): mixed { return $operation(); }
            public function get(string $agentId, ?string $tenantId): ?array { return $this->states[json_encode([$agentId, $tenantId])] ?? null; }
            public function put(string $agentId, ?string $tenantId, array $state): void { $this->states[json_encode([$agentId, $tenantId])] = $state; }
            public function delete(string $agentId, ?string $tenantId): void { unset($this->states[json_encode([$agentId, $tenantId])]); }
            public function compareAndPut(string $agentId, ?string $tenantId, array $expected, array $state): bool {
                $current = $this->get($agentId, $tenantId) ?? [];
                foreach ($expected as $key => $value) {
                    if (($current[$key] ?? null) !== $value) { return false; }
                }
                $this->put($agentId, $tenantId, array_merge($current, $state));
                return true;
            }
        };
        $service = new AgentCompositionService(new AgentCapabilityMapResolver($root), $this->factory(), $states);
        $service->registerMap($root);
        $denied = new AgentRuntimeContext();
        $allowed = new AgentRuntimeContext(capabilities: ['review.execute']);
        $this->assertSame('denied', $service->start('opus.central', $denied)->status());
        $this->assertSame(0, $this->builds);
        $this->assertTrue($service->start('opus.central', $allowed)->ok());
        $this->assertTrue($service->execute('opus.central', ['input' => []], $allowed)->ok());
        $this->assertTrue($service->suspend('opus.central', $allowed)->ok());
        $this->assertSame('denied', $service->execute('opus.central', [], $allowed)->status());
        $this->assertTrue($service->resume('opus.central', $allowed)->ok());
        $this->assertTrue($service->stop('opus.central', $allowed)->ok());
        $this->assertTrue($service->stop('opus.central', $allowed)->ok());
        $this->assertSame(1, $this->builds);
        $this->assertSame(1, $this->authorizations);
    }

    public function testApplicationsSharingTenantAndAgentHaveSeparateNativeLifecycleAndSubjects(): void
    {
        $root = new AgentCapabilityMap(1, 'application', ['opus.central' => [
            'mode' => 'central', 'profile' => 'review', 'permissions' => ['review.execute'],
            'tools' => [], 'imports' => [], 'exports' => [],
        ]]);
        $lock = $this->createMock(IAgentRuntimeStateSynchronizer::class);
        $lock->method('synchronized')->willReturnCallback(fn ($scope, $operation) => $operation());
        $base = new AgentRuntimeStateStore(new Storage(), $lock);
        $factory = $this->factory();
        $services = $contexts = [];
        foreach (['app-a', 'app-b'] as $id) {
            $scope = new AgentApplicationScope($id);
            $services[$id] = new AgentCompositionService(new AgentCapabilityMapResolver($root), $factory,
                new ApplicationAgentRuntimeStateStore($scope, $base));
            $services[$id]->registerMap($root);
            $contexts[$id] = new AgentRuntimeContext('same-tenant', capabilities: ['review.execute'], applicationScope: $scope);
        }
        $a = $services['app-a'];
        $b = $services['app-b'];
        $this->assertTrue($a->start('opus.central', $contexts['app-a'])->ok());
        $this->assertSame('registered', $b->inspect('opus.central', $contexts['app-b'])->state());
        $this->assertSame('denied', $a->execute('opus.central', [], $contexts['app-b'])->status());
        $this->assertSame(0, $this->authorizations);
        $this->assertSame(1, $this->builds);
        $this->assertTrue($b->start('opus.central', $contexts['app-b'])->ok());
        foreach (['app-a', 'app-b'] as $id) {
            $result = $services[$id]->execute('opus.central', ['input' => [], 'application_id' => 'forged'], $contexts[$id]);
            $this->assertTrue($result->ok());
            $this->assertSame($id, $result->toArray()['metadata']['application_id']);
        }
        $this->assertNotSame($this->requests[0]['subject_id'], $this->requests[1]['subject_id']);
        $this->assertTrue($a->stop('opus.central', $contexts['app-a'])->ok());
        $this->assertSame('running', $b->inspect('opus.central', $contexts['app-b'])->state());
        $this->assertSame(2, $this->builds);
    }

    public function testNativeApplicationScopeIsDistinctFromMissingAndForeignScope(): void
    {
        $descriptor = new AgentDescriptor('opus.central', 'application', ['mode' => 'central', 'profile' => 'review']);
        $factory = $this->factory();
        $a = new AgentRuntimeContext(applicationScope: new AgentApplicationScope('app-a'));
        $b = new AgentRuntimeContext(applicationScope: new AgentApplicationScope('app-b'));
        $runtime = $factory->create($descriptor, $a);
        foreach ([$b, new AgentRuntimeContext()] as $foreign) {
            foreach (['start', 'suspend', 'resume', 'stop'] as $action) {
                $this->assertSame('denied', $runtime->{$action}($foreign)->status());
            }
            $this->assertSame('denied', $runtime->cancel('execution', $foreign)->status());
            $this->assertSame('denied', $runtime->execute('execution', [], $foreign)->status());
        }
        $this->assertSame(0, $this->authorizations);
        $this->assertTrue($runtime->execute('a', ['input' => []], $a)->ok());
        $this->assertTrue($factory->create($descriptor, $b)->execute('b', ['input' => []], $b)->ok());
        $this->assertNotSame($this->requests[0]['subject_id'], $this->requests[1]['subject_id']);
        $this->assertSame('app-a', $a->toArray()['application_id']);
        $this->assertArrayNotHasKey('application_id', (new AgentRuntimeContext())->toArray());
    }

    private function descriptor(string $profile = 'review'): AgentDescriptor
    {
        return new AgentDescriptor('review.specialist', 'review', [
            'mode' => 'specialist', 'profile' => $profile,
        ]);
    }

    private function factory(?callable $authorize = null, array $policy = []): AutomataStrategyRuntimeFactory
    {
        return new AutomataStrategyRuntimeFactory(['review' => array_replace([
            'capability_id' => 'review', 'capability_version' => '1',
            'candidates' => [['id' => 'tree', 'version' => '1']],
            'limits' => ['max_invocations' => 1],
            'selection_policy' => 'adaptive',
        ], $policy)], function (): StrategyRouter {
            ++$this->builds;
            $tree = new DecisionTree();
            $tree->setRoot(new Node(['decision' => 'review'], function (): int {
                ++$this->evaluations;
                if ($this->treeThrows) { throw new \RuntimeException('private-adapter-key'); }
                return 1;
            }));
            $adapter = new DecisionTreeRouteAdapter($tree, new DepthFirstTraceMethod(),
                new StrategyDefinition([
                    'id' => 'tree', 'version' => '1', 'capability_id' => 'review',
                    'capability_version' => '1', 'side_effect_free' => true,
                    'availability' => 'available',
                ]), new StrategyEligibility(['eligible' => true]), new StrategyUsage(['invocations' => 1]));
            return new StrategyRouter([$adapter], new Intelligence());
        }, $authorize ?? function (AgentDescriptor $descriptor, AgentRuntimeContext $context, StrategyRouteRequest $request): AutonomyDecision {
            ++$this->authorizations;
            $this->requests[] = $request->toArray();
            return new AutonomyDecision([
                'allowed' => $this->allowed, 'subject_id' => $request->subject_id,
                'capability_id' => $request->capability_id, 'capability_version' => $request->capability_version,
            ]);
        });
    }
}
