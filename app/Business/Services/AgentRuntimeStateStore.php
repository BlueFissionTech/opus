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

    public function __construct(private Storage $storage)
    {
    }

    public function get(string $agentId, ?string $tenantId): ?array
    {
        $state = $this->states()->get($this->key($agentId, $tenantId));

        return Arr::is($state) ? (array) $state : null;
    }

    public function put(string $agentId, ?string $tenantId, array $state): void
    {
        $states = $this->states();
        $states->set($this->key($agentId, $tenantId), $state);
        $this->persist($states);
    }

    public function delete(string $agentId, ?string $tenantId): void
    {
        $states = $this->states();
        $states->delete($this->key($agentId, $tenantId));
        $this->persist($states);
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
        return Str::make(Str::isNotEmpty((string) $tenantId) ? (string) $tenantId : 'application')
            ->append('::')
            ->append($agentId)
            ->val();
    }
}
