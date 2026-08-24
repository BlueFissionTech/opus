<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\IAgentRuntimeStateStore;
use BlueFission\Arr;
use BlueFission\Data\Storage\Storage;
use BlueFission\Str;

final class AgentRuntimeStateStore implements IAgentRuntimeStateStore
{
    private const FIELD = 'agentRuntimeStates';
    private string $lockPath;

    public function __construct(private Storage $storage, ?string $lockPath = null)
    {
        $this->lockPath = $lockPath
            ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opus-agent-runtime-state.lock';
    }

    public function get(string $agentId, ?string $tenantId): ?array
    {
        $state = $this->synchronized(
            fn () => $this->states()->get($this->key($agentId, $tenantId))
        );

        return Arr::is($state) ? (array) $state : null;
    }

    public function put(string $agentId, ?string $tenantId, array $state): void
    {
        $this->synchronized(function () use ($agentId, $tenantId, $state): void {
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
        return $this->synchronized(function () use ($agentId, $tenantId, $expected, $state): bool {
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
        $this->synchronized(function () use ($agentId, $tenantId): void {
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

    private function synchronized(callable $operation): mixed
    {
        $handle = fopen($this->lockPath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Agent runtime state lock is unavailable.');
        }

        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
