<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentRuntimeStateStore;
use App\Business\Services\ApplicationAgentRuntimeStateStore;
use App\Domain\Agents\AgentApplicationScope;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateSynchronizer;
use App\Domain\Agents\IRetirableAgentRuntime;
use BlueFission\Data\Storage\Storage;
use PHPUnit\Framework\TestCase;

final class AgentRuntimeRetirementTest extends TestCase
{
    public function testRetiresOnlyStaleLocalHandleWithoutChangingCurrentState(): void
    {
        [$host, $states, $context, $runtime] = $this->fixture();
        $runtime->expects($this->once())->method('retire')->willReturn(
            AgentRuntimeResult::completed('retire', 'principal', 'a', 'retired')
        );
        $before = $states->get('principal', 'a');
        $this->assertTrue($host->retire('principal', $context)->ok());
        $this->assertSame($before, $states->get('principal', 'a'));
        $again = $host->retire('principal', $context);
        $this->assertTrue($again->ok());
        $this->assertFalse($again->toArray()['metadata']['local_runtime_present']);
    }

    public function testCurrentRuntimeMustUseOrdinaryUnregistration(): void
    {
        [$host, , $context, $runtime] = $this->fixture(stale: false);
        $runtime->expects($this->never())->method('retire');
        $this->assertSame(['agent_runtime_not_stale'], $host->retire('principal', $context)->diagnostics());
    }

    public function testAuthorizationAndApplicationScopeAreNotBypassed(): void
    {
        [$host, $states, , $runtime] = $this->fixture();
        $runtime->expects($this->never())->method('retire');
        $before = $states->get('principal', 'a');
        $revoked = new AgentRuntimeContext('a', applicationScope: new AgentApplicationScope('app'));
        $foreign = new AgentRuntimeContext('a', capabilities: ['operate'], applicationScope: new AgentApplicationScope('other'));
        $this->assertSame(['agent_permission_denied'], $host->retire('principal', $revoked)->diagnostics());
        $this->assertSame(['agent_application_scope_mismatch'], $host->retire('principal', $foreign)->diagnostics());
        $this->assertSame($before, $states->get('principal', 'a'));
    }

    public function testOtherTenantDoesNotReleaseOriginalHandle(): void
    {
        [$host, , $context, $runtime] = $this->fixture();
        $runtime->expects($this->once())->method('retire')->willReturn(
            AgentRuntimeResult::completed('retire', 'principal', 'a', 'retired')
        );
        $foreign = new AgentRuntimeContext('b', capabilities: ['operate'], applicationScope: new AgentApplicationScope('app'));
        $this->assertFalse($host->retire('principal', $foreign)->toArray()['metadata']['local_runtime_present']);
        $this->assertTrue($host->retire('principal', $context)->ok());
    }

    public function testUnsupportedRuntimeRetainsItsHandleWithoutCallingStop(): void
    {
        [$host, , $context, $runtime] = $this->fixture(supported: false);
        $runtime->expects($this->never())->method('stop');
        foreach ([1, 2] as $attempt) {
            $this->assertSame(['agent_runtime_retirement_unsupported'], $host->retire('principal', $context)->diagnostics());
            $this->assertSame(['agent_runtime_registration_stale'], $host->execute('principal', [], $context)->diagnostics());
        }
    }

    /** @dataProvider unsuccessfulCleanup */
    public function testFailedOrUncertainCleanupRetainsHandleForExplicitIdempotentRetry(string $failure): void
    {
        [$host, $states, $context, $runtime] = $this->fixture();
        $calls = 0;
        $runtime->expects($this->exactly(2))->method('retire')->willReturnCallback(function () use (&$calls, $failure): AgentRuntimeResult {
            if (++$calls === 1) {
                if ($failure === 'throw') { throw new \RuntimeException('private-cleanup-detail'); }
                return new AgentRuntimeResult($failure === 'contradictory', 'failed', 'retire', 'principal', 'a', 'unknown');
            }
            return AgentRuntimeResult::completed('retire', 'principal', 'a', 'retired');
        });
        $before = $states->get('principal', 'a');
        $first = $host->retire('principal', $context);
        $this->assertFalse($first->ok());
        $this->assertStringNotContainsString('private-', serialize($first->toArray()));
        $this->assertSame(['agent_runtime_registration_stale'], $host->execute('principal', [], $context)->diagnostics());
        $this->assertTrue($host->retire('principal', $context)->ok());
        $this->assertSame($before, $states->get('principal', 'a'));
    }

    public static function unsuccessfulCleanup(): array
    {
        return [['failed'], ['throw'], ['contradictory']];
    }

    public function testReentrantCleanupCannotAdmitWorkAndInterveningRegistrationIsPreserved(): void
    {
        [$host, $states, $context, $runtime, $map] = $this->fixture();
        $replacement = null;
        $runtime->expects($this->once())->method('retire')->willReturnCallback(function () use ($host, $states, $context, $map, &$replacement): AgentRuntimeResult {
            foreach (['retire', 'start', 'stop', 'registerRuntime', 'unregister', 'inspect'] as $method) {
                $this->assertSame(['agent_runtime_retirement_in_progress'], $host->{$method}('principal', $context)->diagnostics());
            }
            $this->assertSame(['agent_runtime_retirement_in_progress'], $host->execute('principal', [], $context)->diagnostics());
            $this->assertSame(['agent_runtime_retirement_in_progress'], $host->registerMap($map)->diagnostics());
            $replacement = array_replace($states->get('principal', 'a'), ['registration_id' => 'newer-again', 'state' => 'suspended']);
            $states->put('principal', 'a', $replacement);
            return AgentRuntimeResult::completed('retire', 'principal', 'a', 'retired');
        });
        $this->assertTrue($host->retire('principal', $context)->ok());
        $this->assertSame($replacement, $states->get('principal', 'a'));
        $this->assertTrue($host->retire('principal', $context)->ok());
    }

    public function testInactiveAddonCannotUseRetirementToBypassAdmission(): void
    {
        [$host, , , $runtime] = $this->fixture(owner: 'addon');
        $runtime->expects($this->never())->method('retire');
        $inactive = new AgentRuntimeContext('a', capabilities: ['operate'], applicationScope: new AgentApplicationScope('app'));
        $this->assertSame('denied', $host->retire('principal', $inactive)->status());
    }

    private function fixture(bool $stale = true, bool $supported = true, string $owner = 'application'): array
    {
        $map = new AgentCapabilityMap(1, $owner, ['principal' => [
            'mode' => $owner === 'application' ? 'central' : 'specialist',
            'profile' => 'test', 'tools' => [], 'permissions' => ['operate'], 'lifecycle' => ['states' => ['active']],
        ]]);
        $runtime = $this->createMock($supported ? IRetirableAgentRuntime::class : IAgentRuntime::class);
        $runtime->method('start')->willReturn(AgentRuntimeResult::completed('start', 'principal', 'a', 'running'));
        $runtime->expects($this->never())->method('execute');
        $factory = $this->createMock(IAgentRuntimeFactory::class);
        $factory->expects($this->once())->method('available')->willReturn(true);
        $factory->expects($this->once())->method('create')->willReturn($runtime);
        $lock = $this->createMock(IAgentRuntimeStateSynchronizer::class);
        $lock->method('synchronized')->willReturnCallback(fn ($scope, $operation) => $operation());
        $scope = new AgentApplicationScope('app');
        $states = new ApplicationAgentRuntimeStateStore($scope, new AgentRuntimeStateStore(new Storage(), $lock));
        $host = new AgentCompositionService(new AgentCapabilityMapResolver($map), $factory, $states);
        $host->registerMap($map);
        $context = new AgentRuntimeContext('a', activeAddOns: [$owner], lifecycleStates: [$owner => 'active'],
            capabilities: ['operate'], applicationScope: $scope);
        $this->assertTrue($host->start('principal', $context)->ok());
        if ($stale) {
            $states->put('principal', 'a', array_replace($states->get('principal', 'a'), ['registration_id' => 'replacement']));
        }
        return [$host, $states, $context, $runtime, $map];
    }
}
