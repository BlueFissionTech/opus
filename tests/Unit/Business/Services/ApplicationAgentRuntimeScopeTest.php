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
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateStore;
use App\Domain\Agents\IAgentRuntimeStateSynchronizer;
use BlueFission\Data\Storage\Storage;
use PHPUnit\Framework\TestCase;

final class ApplicationAgentRuntimeScopeTest extends TestCase
{
    public function testSharedStoreIsolatesApplicationsTenantsClaimsAndLegacyRecords(): void
    {
        $lock = $this->createMock(IAgentRuntimeStateSynchronizer::class);
        $lock->method('synchronized')->willReturnCallback(fn ($scope, $operation) => $operation());
        $base = new AgentRuntimeStateStore(new Storage(), $lock);
        $a = new ApplicationAgentRuntimeStateStore(new AgentApplicationScope('app-a'), $base);
        $b = new ApplicationAgentRuntimeStateStore(new AgentApplicationScope('app-b'), $base);
        $base->put('opus.central', 'same-tenant', ['state' => 'legacy']);
        $a->put('opus.central', 'same-tenant', ['state' => 'running']);
        $a->put('opus.central', null, ['state' => 'application']);
        $this->assertNull($b->get('opus.central', 'same-tenant'));
        $this->assertNull($a->get('opus.central', 'other-tenant'));
        $this->assertSame('application', $a->get('opus.central', null)['state']);
        $this->assertTrue($b->compareAndPut('opus.central', 'same-tenant', ['state' => null], ['state' => 'stopped']));
        $this->assertFalse($b->compareAndPut('opus.central', 'same-tenant', ['state' => 'running'], ['state' => 'forged']));
        $this->assertSame('running', $a->get('opus.central', 'same-tenant')['state']);
        $a->delete('opus.central', 'same-tenant');
        $this->assertNull($a->get('opus.central', 'same-tenant'));
        $this->assertSame('stopped', $b->get('opus.central', 'same-tenant')['state']);
        $this->assertSame('legacy', $base->get('opus.central', 'same-tenant')['state']);
        $reopened = new ApplicationAgentRuntimeStateStore(new AgentApplicationScope('app-b'), $base);
        $this->assertSame($b->get('opus.central', 'same-tenant'), $reopened->get('opus.central', 'same-tenant'));
    }

    public function testOperationLockNamespacesAreStableAndApplicationSpecific(): void
    {
        $scopes = [];
        $base = $this->createMock(IAgentRuntimeStateStore::class);
        $base->method('synchronized')->willReturnCallback(function ($scope, $operation) use (&$scopes) {
            $scopes[] = $scope;
            return $operation();
        });
        foreach (['app-a', 'app-b', 'app-a'] as $id) {
            $store = new ApplicationAgentRuntimeStateStore(new AgentApplicationScope($id), $base);
            $this->assertSame('done', $store->synchronized('same-operation', fn () => 'done'));
        }
        $this->assertNotSame($scopes[0], $scopes[1]);
        $this->assertSame($scopes[0], $scopes[2]);
    }

    public function testScopeDenialPrecedesEveryLifecycleStateReadAndRuntimeProbe(): void
    {
        $base = $this->createMock(IAgentRuntimeStateStore::class);
        foreach (['get', 'put', 'delete', 'compareAndPut', 'synchronized'] as $method) {
            $base->expects($this->never())->method($method);
        }
        $factory = $this->createMock(IAgentRuntimeFactory::class);
        $factory->expects($this->never())->method('available');
        $factory->expects($this->never())->method('create');
        $map = new AgentCapabilityMap(1, 'application', ['opus.central' => [
            'mode' => 'central', 'profile' => 'review', 'tools' => [], 'permissions' => [],
        ]]);
        $service = new AgentCompositionService(new AgentCapabilityMapResolver($map), $factory,
            new ApplicationAgentRuntimeStateStore(new AgentApplicationScope('app-a'), $base));
        $service->registerMap($map);
        foreach ([null, new AgentApplicationScope('app-b')] as $scope) {
            $context = new AgentRuntimeContext('same-tenant', applicationScope: $scope);
            foreach (['inspect', 'start', 'suspend', 'resume', 'stop', 'cancel', 'execute'] as $action) {
                $result = $action === 'execute'
                    ? $service->execute('opus.central', [], $context)
                    : $service->{$action}('opus.central', $context);
                $this->assertSame('denied', $result->status());
                $this->assertSame('unknown', $result->state());
                $this->assertSame(['agent_application_scope_mismatch'], $result->diagnostics());
                $this->assertStringNotContainsString('app-a', serialize($result->toArray()));
            }
        }
    }

    public function testLegacyCompositionCannotAcceptAnExplicitApplicationClaim(): void
    {
        $base = $this->createMock(IAgentRuntimeStateStore::class);
        $base->expects($this->never())->method('get');
        $factory = $this->createMock(IAgentRuntimeFactory::class);
        $factory->expects($this->never())->method('available');
        $map = new AgentCapabilityMap(1, 'application', []);
        $service = new AgentCompositionService(new AgentCapabilityMapResolver($map), $factory, $base);
        $result = $service->inspect('opus.central', new AgentRuntimeContext(applicationScope: new AgentApplicationScope('app-a')));
        $this->assertSame(['agent_application_scope_mismatch'], $result->diagnostics());
    }

    public function testApplicationBindingCannotBeStackedOrRebound(): void
    {
        $base = $this->createMock(IAgentRuntimeStateStore::class);
        $bound = new ApplicationAgentRuntimeStateStore(new AgentApplicationScope('app-a'), $base);
        $this->expectException(\InvalidArgumentException::class);
        new ApplicationAgentRuntimeStateStore(new AgentApplicationScope('app-b'), $bound);
    }

    /** @dataProvider invalidApplicationIds */
    public function testInvalidApplicationIdentityCannotBeBound(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentApplicationScope($id);
    }

    public static function invalidApplicationIds(): array
    {
        return [[''], [' '], [' app-a'], ['App-A'], ['app:a'], ['../app'], [str_repeat('a', 129)]];
    }
}
