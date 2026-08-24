<?php

declare(strict_types=1);

namespace App\Domain\Agents;

interface IAgentRuntime
{
    public function start(AgentRuntimeContext $context): AgentRuntimeResult;

    public function suspend(AgentRuntimeContext $context): AgentRuntimeResult;

    public function resume(AgentRuntimeContext $context): AgentRuntimeResult;

    public function stop(AgentRuntimeContext $context): AgentRuntimeResult;

    public function cancel(AgentRuntimeContext $context): AgentRuntimeResult;

    public function execute(array $task, AgentRuntimeContext $context): AgentRuntimeResult;
}
