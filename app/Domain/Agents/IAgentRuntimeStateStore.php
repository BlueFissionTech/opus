<?php

declare(strict_types=1);

namespace App\Domain\Agents;

interface IAgentRuntimeStateStore
{
    public function get(string $agentId, ?string $tenantId): ?array;

    public function put(string $agentId, ?string $tenantId, array $state): void;

    public function delete(string $agentId, ?string $tenantId): void;
}
