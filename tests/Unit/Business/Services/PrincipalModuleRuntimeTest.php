<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentRuntimeStateStore;
use App\Business\Services\ApplicationAgentRuntimeStateStore;
use App\Business\Services\PrincipalModuleRuntimeFactory;
use App\Domain\Agents\AgentApplicationScope;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\IAgentRuntimeStateSynchronizer;
use BlueFission\Arr;
use BlueFission\Automata\LLM\Agent\Capability\AutonomyDecision;
use BlueFission\Automata\LLM\Agent;
use BlueFission\Automata\LLM\Agent\State\AgentModuleResult;
use BlueFission\Automata\LLM\Agent\State\AgentState;
use BlueFission\Automata\LLM\Agent\State\CallableAgentModule;
use BlueFission\Automata\LLM\Agent\State\IAgentModule;
use BlueFission\Data\Storage\Storage;
use PHPUnit\Framework\TestCase;

final class PrincipalModuleRuntimeTest extends TestCase
{
    private int $builds = 0;
    private int $evaluations = 0;
    private bool $allowed = true;
    private array $contexts = [];
    private ?string $wrongBinding = null;

    public function testProbeAndStartupAreSideEffectFreeAndFactoryIsPrincipalOnly(): void
    {
        $factory = $this->factory();
        $context = $this->context();
        $this->assertTrue($factory->available($this->descriptor(), $context));
        $this->assertFalse($factory->available($this->descriptor('specialist'), $context));
        $this->assertFalse($factory->available($this->descriptor('disabled'), $context));
        $this->assertFalse($factory->available($this->descriptor(), new AgentRuntimeContext('a')));
        $this->assertFalse($this->factory(enabled: false)->available($this->descriptor(), $context));
        $this->assertTrue($factory->create($this->descriptor(), $context)->start($context)->ok());
        $this->assertSame(0, $this->builds);
        $this->assertSame(0, $this->evaluations);
    }

    public function testActualHostRunsAdvisoryModuleWithTrustedScopeAndFreshRevocation(): void
    {
        [$host, $context] = $this->host();
        $this->assertTrue($host->start('principal', $context)->ok());
        $result = $host->execute('principal', ['applicationId' => 'forged', 'agentId' => 'forged',
            'tenantId' => 'forged', 'profile' => 'forged', 'input' => ['requestId' => 'sample']], $context);
        $this->assertTrue($result->ok());
        $this->assertSame('none', $result->output()['executionAuthority']);
        $this->assertTrue($result->output()['reviewRequired']);
        $this->assertSame('app', $this->contexts[0]['applicationId']);
        $this->assertSame('principal', $this->contexts[0]['agentId']);
        $this->assertSame('a', $this->contexts[0]['tenantId']);
        $this->assertSame(['requestId' => 'sample'], $this->contexts[0]['cogito']);
        $this->assertFalse($result->toArray()['metadata']['usage_known']);
        $this->assertArrayNotHasKey('usage', $result->toArray()['metadata']);
        $this->assertSame(1, $this->builds);
        $this->assertSame(1, $this->evaluations);
        $this->allowed = false;
        $this->assertSame('denied', $host->execute('principal', [], $context)->status());
        $this->assertSame(1, $this->evaluations);
    }

    public function testRevokedBeforeFirstEvaluationDoesNotConstructModule(): void
    {
        $runtime = $this->factory()->create($this->descriptor(), $this->context());
        $this->allowed = false;
        $this->assertSame('denied', $runtime->execute('denied', [], $this->context())->status());
        $this->assertSame(0, $this->builds);
    }

    public function testForeignApplicationAndTenantNeverEvaluate(): void
    {
        $runtime = $this->factory()->create($this->descriptor(), $this->context());
        foreach ([$this->context('other'), $this->context('app', 'b')] as $foreign) {
            $this->assertSame('denied', $runtime->execute('denied', [], $foreign)->status());
        }
        $this->assertSame(0, $this->builds);
        $this->assertSame(0, $this->evaluations);
    }

    public function testNullTenantIsExplicitApplicationScope(): void
    {
        $context = $this->context('app', null);
        $runtime = $this->factory()->create($this->descriptor(), $context);
        $this->assertTrue($runtime->execute('application', [], $context)->ok());
        $this->assertArrayHasKey('tenantId', $this->contexts[0]);
        $this->assertNull($this->contexts[0]['tenantId']);
    }

    /** @dataProvider authorityViolations */
    public function testAdvisoryGuardRejectsWritesDecisionAndStateMutation(string $violation): void
    {
        $factory = $this->factory(function (AgentState $state) use ($violation): AgentModuleResult {
            if ($violation === 'mutation') { $state->write('outputs', 'forged', 'changed'); }
            return new AgentModuleResult([
                'writes' => $violation === 'writes' ? [['channel' => 'outputs', 'key' => 'forged', 'value' => 'changed']] : [],
                'decision' => $violation === 'decision' ? 'execute' : null,
            ]);
        });
        $runtime = $factory->create($this->descriptor(), $this->context());
        $result = $runtime->execute('invalid', [], $this->context());
        $this->assertFalse($result->ok());
        $this->assertSame(['agent_module_advisory_violation'], $result->diagnostics());
        $agent = (new \ReflectionProperty($runtime, 'agent'))->getValue($runtime);
        $this->assertInstanceOf(Agent::class, $agent);
        $this->assertSame((new AgentState())->snapshot(), $agent->agentState()->snapshot());
        $this->assertSame('denied', $runtime->execute('again', [], $this->context())->status());
    }

    public static function authorityViolations(): array { return [['writes'], ['decision'], ['mutation']]; }

    public function testRetirementPermanentlyFencesNativeModuleRuntime(): void
    {
        $context = $this->context();
        $runtime = $this->factory()->create($this->descriptor(), $context);
        $this->assertTrue($runtime->execute('before', [], $context)->ok());
        $this->assertTrue($runtime->retire($context)->ok());
        $this->assertTrue($runtime->retire($context)->ok());
        $this->assertSame('denied', $runtime->execute('after', [], $context)->status());
        $this->assertSame(1, $this->evaluations);
    }

    /** @dataProvider authorizationBindings */
    public function testWrongAuthorizationBindingsDenyBeforeConstruction(string $binding): void
    {
        $this->wrongBinding = $binding;
        $runtime = $this->factory()->create($this->descriptor(), $this->context());
        $this->assertSame('denied', $runtime->execute('wrong', [], $this->context())->status());
        $this->assertSame(0, $this->builds);
    }

    public static function authorizationBindings(): array { return [['subject_id'], ['capability_id'], ['capability_version']]; }

    public function testInputMappingCannotOverwriteTrustedIdentity(): void
    {
        foreach (['applicationId', 'agentId', 'tenantId', 'correlationId', 'executionId'] as $key) {
            $this->assertFalse($this->factory(inputKey: $key)->available($this->descriptor(), $this->context()));
        }
        $this->assertSame(0, $this->builds);
    }

    public function testSharedModuleObjectCannotCrossApplications(): void
    {
        $module = new CallableAgentModule('shared', fn () => new AgentModuleResult());
        $factory = new PrincipalModuleRuntimeFactory(['advisory' => [
            'enabled' => true, 'capability_id' => 'advice', 'capability_version' => '1',
        ]], fn () => $module, fn ($descriptor, $context, $request) => new AutonomyDecision([
            'allowed' => true, 'subject_id' => $request->subject_id,
            'capability_id' => $request->capability_id, 'capability_version' => $request->capability_version,
        ]));
        $a = $factory->create($this->descriptor(), $this->context());
        $b = $factory->create($this->descriptor(), $this->context('other'));
        $this->assertTrue($a->execute('a', [], $this->context())->ok());
        $this->assertFalse($b->execute('b', [], $this->context('other'))->ok());
        $this->assertTrue($a->execute('a2', [], $this->context())->ok());
    }

    public function testModuleCannotReenterEvaluationOrRetireInFlight(): void
    {
        $runtime = null;
        $context = $this->context();
        $factory = $this->factory(function () use (&$runtime, $context): AgentModuleResult {
            $this->assertSame(['agent_execution_in_progress'], $runtime->execute('nested', [], $context)->diagnostics());
            $this->assertSame(['agent_execution_in_progress'], $runtime->retire($context)->diagnostics());
            return new AgentModuleResult();
        });
        $runtime = $factory->create($this->descriptor(), $context);
        $this->assertTrue($runtime->execute('first', [], $context)->ok());
        $this->assertTrue($runtime->retire($context)->ok());
    }

    public function testModuleExceptionIsPrivateAndCannotBeSilentlyReplayed(): void
    {
        $runtime = $this->factory(function (): never { throw new \RuntimeException('private-provider-token'); })
            ->create($this->descriptor(), $this->context());
        $result = $runtime->execute('first', [], $this->context());
        $this->assertFalse($result->ok());
        $this->assertStringNotContainsString('private-', serialize($result->toArray()));
        $this->assertSame('denied', $runtime->execute('retry', [], $this->context())->status());
        $this->assertSame(1, $this->evaluations);
        $this->assertTrue($runtime->stop($this->context())->ok());
    }

    public function testHostRevokedCapabilityBlocksBeforeModuleConstruction(): void
    {
        [$host, $context] = $this->host();
        $this->assertTrue($host->start('principal', $context)->ok());
        $revoked = new AgentRuntimeContext('a', applicationScope: new AgentApplicationScope('app'));
        $this->assertSame('denied', $host->execute('principal', ['enabled' => true], $revoked)->status());
        $this->assertSame(0, $this->builds);
        $this->assertSame(0, $this->evaluations);
    }

    public function testProfilesAreSnapshottedAndAbsentEnablementIsDisabled(): void
    {
        $profile = Arr::make(['enabled' => true, 'capability_id' => 'advice', 'capability_version' => '1']);
        $factory = new PrincipalModuleRuntimeFactory(['advisory' => $profile], fn () => null, fn () => null);
        $profile->set('enabled', false);
        $profile->set('input_key', 'applicationId');
        $this->assertTrue($factory->available($this->descriptor(), $this->context()));
        $disabled = new PrincipalModuleRuntimeFactory(['advisory' => [
            'capability_id' => 'advice', 'capability_version' => '1',
        ]], fn () => null, fn () => null);
        $this->assertFalse($disabled->available($this->descriptor(), $this->context()));
        $malformed = new PrincipalModuleRuntimeFactory(['advisory' => [
            'enabled' => true, 'capability_id' => 'advice', 'capability_version' => '1', 'input_key' => 42,
        ]], fn () => null, fn () => null);
        $this->assertFalse($malformed->available($this->descriptor(), $this->context()));
        $this->expectExceptionMessage('principal_module_unavailable');
        $disabled->create($this->descriptor(), $this->context());
    }

    public function testStopReleasesModuleAndRestartBuildsFreshInstance(): void
    {
        $context = $this->context();
        $runtime = $this->factory()->create($this->descriptor(), $context);
        $this->assertTrue($runtime->execute('first', [], $context)->ok());
        $agent = (new \ReflectionProperty($runtime, 'agent'))->getValue($runtime);
        $this->assertSame((new AgentState())->snapshot(), $agent->agentState()->snapshot());
        $this->assertTrue($runtime->stop($context)->ok());
        $this->assertNull((new \ReflectionProperty($runtime, 'agent'))->getValue($runtime));
        $this->assertTrue($runtime->start($context)->ok());
        $this->assertTrue($runtime->execute('second', [], $context)->ok());
        $this->assertSame(2, $this->builds);
        $this->assertNotSame($agent, (new \ReflectionProperty($runtime, 'agent'))->getValue($runtime));
    }

    /** @dataProvider withheldStatuses */
    public function testIncompleteAdviceDoesNotLeakMetadata(string $status): void
    {
        $runtime = $this->factory(fn () => new AgentModuleResult([
            'status' => $status, 'metadata' => ['secret' => 'private-incomplete'],
        ]))->create($this->descriptor(), $this->context());
        $result = $runtime->execute('incomplete', [], $this->context());
        $this->assertSame('unavailable', $result->status());
        $this->assertStringNotContainsString('private-', serialize($result->toArray()));
    }

    public static function withheldStatuses(): array
    {
        return [['unavailable'], ['disabled'], ['out_of_scope'], ['invalid_input'], ['failed'], ['custom']];
    }

    public function testAdvisoryStatusPreservesAdviceWithoutGrantingAuthority(): void
    {
        $runtime = $this->factory(fn () => new AgentModuleResult([
            'status' => 'advisory', 'metadata' => ['recommendation' => 'review-plan'],
        ]))->create($this->descriptor(), $this->context());
        $result = $runtime->execute('advice', [], $this->context());
        $this->assertTrue($result->ok());
        $this->assertSame('none', $result->output()['executionAuthority']);
        $this->assertTrue($result->output()['reviewRequired']);
        $this->assertSame(['recommendation' => 'review-plan'], $result->output()['advice']);
    }

    public function testReviewRequiredPreservesEvidenceGapsWithoutInventingRecommendation(): void
    {
        $runtime = $this->factory(fn () => new AgentModuleResult([
            'status' => 'review_required', 'metadata' => [
                'recommendation' => '', 'gaps' => ['missing_provenance:need'],
            ],
        ]))->create($this->descriptor(), $this->context());
        $result = $runtime->execute('review', [], $this->context());
        $this->assertTrue($result->ok());
        $this->assertSame('review_required', $result->output()['advisoryStatus']);
        $this->assertSame('none', $result->output()['executionAuthority']);
        $this->assertTrue($result->output()['reviewRequired']);
        $this->assertSame('', $result->output()['advice']['recommendation']);
        $this->assertSame(['missing_provenance:need'], $result->output()['advice']['gaps']);
    }

    /** @dataProvider cleanupBehaviors */
    public function testCleanupReentryAndUncertaintyNeverReadmitWork(bool $throw): void
    {
        $context = $this->context();
        $runtime = null;
        $during = [];
        $factory = new PrincipalModuleRuntimeFactory(['advisory' => [
            'enabled' => true, 'capability_id' => 'advice', 'capability_version' => '1',
        ]], function () use (&$runtime, &$during, $context, $throw): IAgentModule {
            return new class(function () use (&$runtime, &$during, $context, $throw): void {
                $during[] = $runtime->execute('nested', [], $context)->diagnostics();
                $during[] = $runtime->retire($context)->diagnostics();
                if ($throw) { throw new \RuntimeException('private-cleanup'); }
            }) implements IAgentModule {
                public function __construct(private \Closure $cleanup) {}
                public function name(): string { return 'cleanup'; }
                public function process(AgentState $state, array $context = []): AgentModuleResult { return new AgentModuleResult(); }
                public function __destruct() { ($this->cleanup)(); }
            };
        }, fn ($descriptor, $context, $request) => new AutonomyDecision([
            'allowed' => true, 'subject_id' => $request->subject_id,
            'capability_id' => $request->capability_id, 'capability_version' => $request->capability_version,
        ]));
        $runtime = $factory->create($this->descriptor(), $context);
        $this->assertTrue($runtime->execute('first', [], $context)->ok());
        $result = $runtime->retire($context);
        $this->assertSame(!$throw, $result->ok());
        $this->assertSame([['agent_runtime_retirement_in_progress'], ['agent_runtime_retirement_in_progress']], $during);
        $this->assertSame(!$throw, $runtime->retire($context)->ok());
        $this->assertSame('denied', $runtime->execute('after', [], $context)->status());
        $this->assertStringNotContainsString('private-', serialize($result->toArray()));
    }

    public static function cleanupBehaviors(): array { return [[false], [true]]; }

    public function testDistinctApplicationsHaveSeparateAgentAndModuleInstances(): void
    {
        $factory = $this->factory();
        $a = $factory->create($this->descriptor(), $this->context());
        $b = $factory->create($this->descriptor(), $this->context('other'));
        $this->assertTrue($a->execute('a', [], $this->context())->ok());
        $this->assertTrue($b->execute('b', [], $this->context('other'))->ok());
        $this->assertSame(2, $this->builds);
        $this->assertSame(['app', 'other'], array_column($this->contexts, 'applicationId'));
        $this->assertNotSame((new \ReflectionProperty($a, 'agent'))->getValue($a),
            (new \ReflectionProperty($b, 'agent'))->getValue($b));
        $this->assertSame('denied', $a->retire($this->context('other'))->status());
        $this->assertTrue($a->retire($this->context())->ok());
        $this->assertTrue($b->execute('still-available', [], $this->context('other'))->ok());
    }

    private function host(): array
    {
        $map = new AgentCapabilityMap(1, 'application', ['principal' => [
            'mode' => 'central', 'profile' => 'advisory', 'permissions' => ['advice'], 'tools' => [],
        ]]);
        $context = $this->context();
        $lock = $this->createMock(IAgentRuntimeStateSynchronizer::class);
        $lock->method('synchronized')->willReturnCallback(fn ($scope, $operation) => $operation());
        $states = new ApplicationAgentRuntimeStateStore(new AgentApplicationScope('app'), new AgentRuntimeStateStore(new Storage(), $lock));
        $host = new AgentCompositionService(new AgentCapabilityMapResolver($map), $this->factory(), $states);
        $host->registerMap($map);
        return [$host, $context, $states];
    }

    private function factory(?callable $process = null, bool $enabled = true, string $inputKey = 'cogito'): PrincipalModuleRuntimeFactory
    {
        return new PrincipalModuleRuntimeFactory(['advisory' => [
            'enabled' => $enabled, 'capability_id' => 'principal.advice', 'capability_version' => '1', 'input_key' => $inputKey,
        ]], function () use ($process): CallableAgentModule {
            ++$this->builds;
            return new CallableAgentModule('test.advisory', function ($state, $context) use ($process): AgentModuleResult {
                ++$this->evaluations;
                $this->contexts[] = $context;
                return $process ? $process($state, $context) : new AgentModuleResult(['metadata' => ['advice' => 'review']]);
            });
        }, function ($descriptor, $context, $request): AutonomyDecision {
            $decision = ['allowed' => $this->allowed, 'subject_id' => $request->subject_id,
                'capability_id' => $request->capability_id, 'capability_version' => $request->capability_version];
            if ($this->wrongBinding !== null) { $decision[$this->wrongBinding] = 'wrong'; }
            return new AutonomyDecision($decision);
        });
    }

    private function descriptor(string $mode = 'central'): AgentDescriptor
    {
        return new AgentDescriptor('principal', 'application', ['mode' => $mode, 'profile' => 'advisory']);
    }

    private function context(string $application = 'app', ?string $tenant = 'a'): AgentRuntimeContext
    {
        return new AgentRuntimeContext($tenant, capabilities: ['advice'], correlationId: 'host-correlation',
            applicationScope: new AgentApplicationScope($application));
    }
}
