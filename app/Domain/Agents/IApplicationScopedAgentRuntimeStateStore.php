<?php

declare(strict_types=1);

namespace App\Domain\Agents;

interface IApplicationScopedAgentRuntimeStateStore extends IAgentRuntimeStateStore
{
    public function applicationScope(): AgentApplicationScope;
}
