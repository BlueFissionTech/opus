<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\AgentCapabilityMapResolver;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentRuntimeStateStore;
use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateSynchronizer;
use BlueFission\Data\Storage\Disk;
use BlueFission\Data\Storage\Storage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentRuntimeStateStoreOutcomeTest extends TestCase
{
    /** @dataProvider operations */
    public function testUnactivatedNativeDiskNeverReturnsAClaimOrState(string $operation): void
    {
        $storage = new Disk();
        $store = $this->store($storage);
        $failure = null;
        try {
            $this->invoke($store, $operation);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(RuntimeException::class, $failure, 'Explicit native read failure was accepted.');
        self::assertSame('agent_runtime_state_read_failed', $failure->getMessage());
        self::assertSame(Storage::STATUS_FAILED, $storage->status());
        self::assertNull((new \ReflectionProperty(Disk::class, '_source'))->getValue($storage));
    }

    public static function operations(): array
    {
        return [['get'], ['put'], ['compareAndPut'], ['delete']];
    }

    /** @dataProvider writeFailures */
    public function testReportedWriteFailureCannotReturnSuccessOrReplay(string $operation, string $status): void
    {
        $storage = new class($status) extends Storage {
            public int $writes = 0;
            public function __construct(private string $failure) { parent::__construct(); }
            protected function _read(): void { $this->status(self::STATUS_SUCCESS); }
            protected function _write(): void { ++$this->writes; $this->status($this->failure); }
        };
        $failure = null;
        try {
            $this->invoke($this->store($storage), $operation);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(RuntimeException::class, $failure, 'Explicit native write failure was accepted.');
        self::assertSame('agent_runtime_state_write_failed', $failure->getMessage());
        self::assertSame(1, $storage->writes);
        self::assertSame($status, $storage->status());
    }

    public static function writeFailures(): array
    {
        $cases = [];
        foreach (['put', 'compareAndPut', 'delete'] as $operation) {
            foreach ([Storage::STATUS_FAILED, Storage::STATUS_FAILED_INIT] as $status) {
                $cases[] = [$operation, $status];
            }
        }
        return $cases;
    }

    public function testNativeReadFailurePreventsCompositionFactoryAndExecution(): void
    {
        $storage = new Disk();
        // Cached PHP fields are not proof that the backing state was read successfully.
        $storage->agentRuntimeStates = ['tenant:tenant-a::opus.central' => ['state' => 'running']];
        $map = $this->map();
        $factory = $this->createMock(IAgentRuntimeFactory::class);
        $factory->expects(self::never())->method('available');
        $factory->expects(self::never())->method('create');
        $service = new AgentCompositionService(new AgentCapabilityMapResolver($map), $factory, $this->store($storage));
        $service->registerMap($map);
        $result = $service->execute('opus.central', [], new AgentRuntimeContext(tenantId: 'tenant-a', correlationId: 'request-a'));
        self::assertSame(AgentRuntimeResult::FAILED, $result->status());
        self::assertFalse($result->ok());
        self::assertFalse($result->toArray()['metadata']['execution_started']);
        self::assertSame('unknown', $result->toArray()['metadata']['effects']);
        self::assertSame('request-a', $result->toArray()['metadata']['correlation_id']);
        self::assertSame(Storage::STATUS_FAILED, $storage->status());
    }

    public function testReportedClaimWriteFailureNeverDispatchesOrClearsUncertainClaim(): void
    {
        $storage = new class extends Storage {
            public int $writes = 0;
            protected function _read(): void { $this->status(self::STATUS_SUCCESS); }
            protected function _write(): void { ++$this->writes; $this->status(self::STATUS_FAILED); }
        };
        $storage->agentRuntimeStates = ['tenant:tenant-a::opus.central' => ['state' => 'running']];
        $runtime = $this->createMock(IAgentRuntime::class);
        $runtime->expects(self::never())->method('execute');
        $factory = $this->createMock(IAgentRuntimeFactory::class);
        $factory->method('available')->willReturn(true);
        $factory->expects(self::once())->method('create')->willReturn($runtime);
        $map = $this->map();
        $service = new AgentCompositionService(new AgentCapabilityMapResolver($map), $factory, $this->store($storage));
        $service->registerMap($map);
        $context = new AgentRuntimeContext(tenantId: 'tenant-a', correlationId: 'claim-a');
        $result = $service->execute('opus.central', [], $context);
        self::assertFalse($result->ok());
        self::assertSame(AgentRuntimeResult::FAILED, $result->status());
        self::assertFalse($result->toArray()['metadata']['execution_started']);
        self::assertSame('unknown', $result->toArray()['metadata']['effects']);
        self::assertSame(1, $storage->writes);
        self::assertNotEmpty($storage->agentRuntimeStates['tenant:tenant-a::opus.central']['active_execution_id']);
        self::assertSame(AgentRuntimeResult::DENIED, $service->execute('opus.central', [], $context)->status());
        self::assertSame(1, $storage->writes);
    }

    public function testBaseNativeStorageCompatibilityAndCompareMismatch(): void
    {
        $store = $this->store(new Storage());
        self::assertNull($store->get('opus.central', null));
        $store->put('opus.central', null, ['state' => 'running']);
        self::assertFalse($store->compareAndPut('opus.central', null, ['state' => 'stopped'], ['state' => 'invalid']));
        self::assertSame('running', $store->get('opus.central', null)['state']);
        self::assertTrue($store->compareAndPut('opus.central', null, ['state' => 'running'], ['state' => 'stopped']));
        $store->delete('opus.central', null);
        self::assertNull($store->get('opus.central', null));
    }

    private function store(Storage $storage): AgentRuntimeStateStore
    {
        $lock = $this->createMock(IAgentRuntimeStateSynchronizer::class);
        $lock->method('synchronized')->willReturnCallback(static fn ($scope, $operation) => $operation());
        return new AgentRuntimeStateStore($storage, $lock);
    }

    private function map(): AgentCapabilityMap
    {
        return new AgentCapabilityMap(1, 'application', ['opus.central' => [
            'mode' => 'central', 'description' => 'Fixture coordinator', 'profile' => 'opus.central',
            'tools' => ['command.list'], 'imports' => [], 'exports' => [], 'permissions' => [],
            'lifecycle' => ['states' => ['active']],
        ]]);
    }

    private function invoke(AgentRuntimeStateStore $store, string $operation): mixed
    {
        return match ($operation) {
            'get' => $store->get('opus.central', null),
            'put' => $store->put('opus.central', null, ['state' => 'running']),
            'compareAndPut' => $store->compareAndPut('opus.central', null, [], ['state' => 'running']),
            'delete' => $store->delete('opus.central', null),
        };
    }
}
