<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\IAgentRuntimeStateStore;
use App\Domain\Agents\IAgentRuntimeStateSynchronizer;
use BlueFission\Arr;
use BlueFission\Data\Storage\Storage;
use BlueFission\Str;

final class AgentRuntimeStateStore implements IAgentRuntimeStateStore
{
    private const FIELD = 'agentRuntimeStates';
    private const LOCK_SCOPE = 'opus-agent-runtime-states';

    public function __construct(
        private Storage $storage,
        private IAgentRuntimeStateSynchronizer $synchronizer
    )
    {
    }

    public function get(string $agentId, ?string $tenantId): ?array
    {
        $state = $this->synchronizedState(
            fn () => $this->states()->get($this->key($agentId, $tenantId))
        );

        return Arr::is($state) ? (array) $state : null;
    }

    public function put(string $agentId, ?string $tenantId, array $state): void
    {
        $this->synchronizedState(function () use ($agentId, $tenantId, $state): void {
            $states = $this->states();
            $states->set($this->key($agentId, $tenantId), $state);
            $this->persist($states);
        });
    }

    public function compareAndPut(
        string $agentId,
        ?string $tenantId,
        array $expected,
        array $state
    ): bool {
        return $this->synchronizedState(function () use ($agentId, $tenantId, $expected, $state): bool {
            $states = $this->states();
            $key = $this->key($agentId, $tenantId);
            $current = (array) $states->get($key);
            $matches = true;
            Arr::make($expected)->each(function ($value, $path) use ($current, &$matches): void {
                $matches = $matches && Arr::getPath($current, (string) $path) === $value;
            });
            if (!$matches) {
                return false;
            }

            $states->set($key, Arr::merge($current, $state));
            $this->persist($states);

            return true;
        });
    }

    public function delete(string $agentId, ?string $tenantId): void
    {
        $this->synchronizedState(function () use ($agentId, $tenantId): void {
            $states = $this->states();
            $states->delete($this->key($agentId, $tenantId));
            $this->persist($states);
        });
    }

    private function states(): Arr
    {
        $this->storage->read();

        return Arr::make((array) ($this->storage->{self::FIELD} ?? []));
    }

    private function persist(Arr $states): void
    {
        $this->storage->{self::FIELD} = $states->toArray();
        $this->storage->write();
    }

    private function key(string $agentId, ?string $tenantId): string
    {
        return Str::make(Str::isNotEmpty((string) $tenantId) ? 'tenant:' . $tenantId : 'scope:application')
            ->append('::')
            ->append($agentId)
            ->val();
    }

    public function synchronized(string $scope, callable $operation): mixed
    {
        $operationScope = Str::make(self::LOCK_SCOPE)
            ->append(':operation:')
            ->append(Str::make($scope)->encrypt('sha256')->val())
            ->val();

        return $this->synchronizer->synchronized($operationScope, $operation);
    }

    private function synchronizedState(callable $operation): mixed
    {
        return $this->synchronizer->synchronized(self::LOCK_SCOPE, $operation);
    }
}
