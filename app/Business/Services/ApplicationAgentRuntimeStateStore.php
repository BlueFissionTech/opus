<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentApplicationScope;
use App\Domain\Agents\IAgentRuntimeStateStore;
use App\Domain\Agents\IApplicationScopedAgentRuntimeStateStore;
use InvalidArgumentException;

final class ApplicationAgentRuntimeStateStore implements IApplicationScopedAgentRuntimeStateStore
{
    public function __construct(
        private AgentApplicationScope $application,
        private IAgentRuntimeStateStore $states
    ) {
        if ($states instanceof IApplicationScopedAgentRuntimeStateStore) {
            throw new InvalidArgumentException('agent_application_scope_already_bound');
        }
    }

    public function applicationScope(): AgentApplicationScope
    {
        return $this->application;
    }

    public function synchronized(string $scope, callable $operation): mixed
    {
        return $this->states->synchronized($this->key($scope), $operation);
    }

    public function get(string $agentId, ?string $tenantId): ?array
    {
        return $this->states->get($this->key($agentId), $tenantId);
    }

    public function put(string $agentId, ?string $tenantId, array $state): void
    {
        $this->states->put($this->key($agentId), $tenantId, $state);
    }

    public function compareAndPut(string $agentId, ?string $tenantId, array $expected, array $state): bool
    {
        return $this->states->compareAndPut($this->key($agentId), $tenantId, $expected, $state);
    }

    public function delete(string $agentId, ?string $tenantId): void
    {
        $this->states->delete($this->key($agentId), $tenantId);
    }

    private function key(string $value): string
    {
        return $this->application->key() . '::' . $value;
    }
}
