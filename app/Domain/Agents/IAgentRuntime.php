<?php

declare(strict_types=1);

namespace App\Domain\Agents;

interface IAgentRuntime
{
    public function start(): AgentRuntimeResult;

    public function suspend(): AgentRuntimeResult;

    public function resume(): AgentRuntimeResult;

    public function stop(): AgentRuntimeResult;

    public function cancel(): AgentRuntimeResult;

    public function execute(array $task): AgentRuntimeResult;
}
