<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\IAgentContinuationScopeStore;
use BlueFission\Arr;
use BlueFission\Data\Storage\Storage;

final class AgentContinuationScopeStore implements IAgentContinuationScopeStore
{
    private const FIELD = 'agentContinuationScopes';

    public function __construct(private Storage $storage)
    {
    }

    public function get(string $token): ?array
    {
        $scopes = $this->scopes();
        $scope = $scopes->get($token);

        return Arr::is($scope) ? (array) $scope : null;
    }

    public function put(string $token, array $scope): void
    {
        $scopes = $this->scopes();
        $scopes->set($token, $scope);
        $this->persist($scopes);
    }

    public function delete(string $token): void
    {
        $scopes = $this->scopes();
        $scopes->delete($token);
        $this->persist($scopes);
    }

    private function scopes(): Arr
    {
        $this->storage->read();

        return Arr::make((array) ($this->storage->{self::FIELD} ?? []));
    }

    private function persist(Arr $scopes): void
    {
        $this->storage->{self::FIELD} = $scopes->toArray();
        $this->storage->write();
    }
}
