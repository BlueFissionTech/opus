<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentRuntimeStateStore;
use App\Business\Services\ExtensionPointCatalog;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateSynchronizer;
use BlueFission\Data\Storage\Storage;
use BlueFission\DevElation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentRuntimeObservationTest extends TestCase
{
    private mixed $active;
    private mixed $actions;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(DevElation::class);
        $this->active = $reflection->getProperty('_isActive')->getValue();
        $this->actions = $reflection->getProperty('_actions')->getValue();
        $reflection->getProperty('_actions')->setValue(null, []);
        DevElation::up();
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(DevElation::class);
        $reflection->getProperty('_isActive')->setValue(null, $this->active);
        $reflection->getProperty('_actions')->setValue(null, $this->actions);
    }

    public function testObservationFollowsPersistenceAndPreservesPrivateResult(): void
    {
        [$service, $states, $context] = $this->fixture();
        $events = [];
        DevElation::action(ExtensionPointCatalog::AGENT_RUNTIME_RESULT, function (array $summary) use (&$events, $states): array {
            $this->assertSame('running', $states->get('private-principal', 'private-tenant')['state']);
            $events[] = ['first', $summary];
            return ['status' => 'failed'];
        }, 1);
        DevElation::action(ExtensionPointCatalog::AGENT_RUNTIME_RESULT, function (array $summary) use (&$events): void {
            $events[] = ['second', $summary];
        }, 2);
        $this->assertTrue($service->start('private-principal', $context)->ok());
        $result = $service->execute('private-principal', ['secret' => 'private-task'], $context);
        $this->assertTrue($result->ok());
        $this->assertSame('private-output', $result->output());
        $this->assertSame(['first', 'second', 'first', 'second'], array_column($events, 0));
        $this->assertSame(['version' => 1, 'operation' => 'execute', 'status' => 'completed', 'ok' => true], $events[2][1]);
        $this->assertStringNotContainsString('private-', serialize($events));
    }

    public function testDeniedResultDoesNotExposeIdentityOrDiagnostics(): void
    {
        [$service, , , $factory] = $this->fixture();
        $factory->expects($this->never())->method('available');
        $factory->expects($this->never())->method('create');
        $summaries = [];
        DevElation::action(ExtensionPointCatalog::AGENT_RUNTIME_RESULT, function (array $summary) use (&$summaries): void { $summaries[] = $summary; });
        $result = $service->start('private-principal', new AgentRuntimeContext(tenantId: 'private-tenant'));
        $this->assertSame(['agent_permission_denied'], $result->diagnostics());
        $this->assertSame([['version' => 1, 'operation' => 'start', 'status' => 'denied', 'ok' => false]], $summaries);
    }

    public function testObserverFailureCannotReplaceResultAndGuardResets(): void
    {
        [$service, , $context] = $this->fixture();
        $calls = $late = 0;
        DevElation::action(ExtensionPointCatalog::AGENT_RUNTIME_RESULT, function () use (&$calls): void {
            ++$calls;
            throw new \Error('private-observer');
        }, 1);
        DevElation::action(ExtensionPointCatalog::AGENT_RUNTIME_RESULT, function () use (&$late): void { ++$late; }, 2);
        $this->assertTrue($service->start('private-principal', $context)->ok());
        $this->assertTrue($service->execute('private-principal', [], $context)->ok());
        $this->assertSame(2, $calls);
        $this->assertSame(0, $late);
    }

    public function testNestedOperationsAcrossInstancesCannotExecuteOrRedeliver(): void
    {
        [$service, , $context, , $map] = $this->fixture();
        [$other, , , $otherFactory] = $this->fixture();
        $otherFactory->expects($this->never())->method('available');
        $otherFactory->expects($this->never())->method('create');
        $calls = 0;
        DevElation::action(ExtensionPointCatalog::AGENT_RUNTIME_RESULT, function () use ($service, $other, $context, $map, &$calls): void {
            ++$calls;
            foreach ([$service, $other] as $host) {
                foreach (['inspect', 'start', 'suspend', 'resume', 'stop', 'cancel', 'unregister', 'registerRuntime', 'retire', 'execute'] as $action) {
                    $result = $action === 'execute'
                        ? $host->execute('private-principal', [], $context)
                        : $host->{$action}('private-principal', $context);
                    $this->assertSame(['agent_result_observer_reentry_denied'], $result->diagnostics());
                    $this->assertSame('unknown', $result->state());
                }
                $this->assertSame(['agent_result_observer_reentry_denied'], $host->registerMap($map)->diagnostics());
            }
        });
        $this->assertTrue($service->start('private-principal', $context)->ok());
        $this->assertSame(1, $calls);
    }

    public function testDisabledHooksPreserveNoHandlerBehavior(): void
    {
        [$service, , $context] = $this->fixture();
        $called = false;
        DevElation::action(ExtensionPointCatalog::AGENT_RUNTIME_RESULT, function () use (&$called): void { $called = true; });
        DevElation::down();
        $this->assertTrue($service->start('private-principal', $context)->ok());
        $this->assertFalse($called);
    }

    public function testRetirementObservationContainsOnlyBoundedOutcome(): void
    {
        [$service, , $context, $factory] = $this->fixture();
        $factory->expects($this->never())->method('available');
        $factory->expects($this->never())->method('create');
        $summaries = [];
        DevElation::action(ExtensionPointCatalog::AGENT_RUNTIME_RESULT, function (array $summary) use (&$summaries): void { $summaries[] = $summary; });
        $this->assertTrue($service->retire('private-principal', $context)->ok());
        $this->assertSame([['version' => 1, 'operation' => 'retire', 'status' => 'completed', 'ok' => true]], $summaries);
    }

    public static function nonSuccessStatuses(): array
    {
        return [['private-status', 'unknown'], ['denied', 'denied'], ['failed', 'failed'], ['unavailable', 'unavailable']];
    }

    /** @dataProvider nonSuccessStatuses */
    public function testRuntimeStatusIsBoundedWithoutChangingOriginalResult(string $status, string $observed): void
    {
        [$service, , $context] = $this->fixture(new AgentRuntimeResult(
            true, $status, 'private-action', 'private-principal', 'private-tenant',
            'private-state', 'private-output', ['private-diagnostic']
        ));
        $summaries = [];
        DevElation::action(ExtensionPointCatalog::AGENT_RUNTIME_RESULT, function (array $summary) use (&$summaries): void { $summaries[] = $summary; });
        $service->start('private-principal', $context);
        $result = $service->execute('private-principal', [], $context);
        $this->assertSame($status, $result->status());
        $this->assertSame('private-output', $result->output());
        $this->assertSame(['version' => 1, 'operation' => 'execute', 'status' => $observed, 'ok' => false], $summaries[1]);
        $this->assertStringNotContainsString('private-', serialize($summaries));
    }

    private function fixture(?AgentRuntimeResult $executionResult = null): array
    {
        $map = new AgentCapabilityMap(1, 'application', ['private-principal' => [
            'mode' => 'central', 'profile' => 'private-profile', 'tools' => [], 'permissions' => ['operate'],
        ]]);
        $runtime = $this->createMock(IAgentRuntime::class);
        foreach (['start', 'execute'] as $action) {
            $result = AgentRuntimeResult::completed(
                $action, 'private-principal', 'private-tenant', 'running', 'private-output', ['secret' => 'private-metadata']
            );
            $runtime->method($action)->willReturn($action === 'execute' ? ($executionResult ?? $result) : $result);
        }
        $factory = $this->createMock(IAgentRuntimeFactory::class);
        $factory->method('available')->willReturn(true);
        $factory->method('create')->willReturn($runtime);
        $lock = $this->createMock(IAgentRuntimeStateSynchronizer::class);
        $lock->method('synchronized')->willReturnCallback(fn ($scope, $operation) => $operation());
        $states = new AgentRuntimeStateStore(new Storage(), $lock);
        $service = new AgentCompositionService(new AgentCapabilityMapResolver($map), $factory, $states);
        $service->registerMap($map);
        return [$service, $states, new AgentRuntimeContext(
            tenantId: 'private-tenant', actor: ['id' => 'private-actor'], capabilities: ['operate'], correlationId: 'private-correlation'
        ), $factory, $map];
    }
}
