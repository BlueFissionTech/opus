<?php

declare(strict_types=1);

namespace App\Domain\Agents;

interface IAgentRuntimeFactory
{
    /** Local new-work/creation probe only: no runtime construction, provider IO or retries. */
    public function available(AgentDescriptor $descriptor, AgentRuntimeContext $context): bool;

    public function create(AgentDescriptor $descriptor, AgentRuntimeContext $context): IAgentRuntime;
}
