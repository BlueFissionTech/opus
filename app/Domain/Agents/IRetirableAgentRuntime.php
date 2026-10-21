<?php

declare(strict_types=1);

namespace App\Domain\Agents;

/** Optional instance-local cleanup, separate from shared registration lifecycle. */
interface IRetirableAgentRuntime extends IAgentRuntime
{
    /**
     * Retire only resources owned by this instance; never resolve a replacement
     * by logical agent ID. Reject in-flight/reentrant cleanup and foreign scope.
     * Success permanently denies further work on this object. Retain handles on
     * failure or uncertainty. Repeated calls, including after partial cleanup or
     * an exception, must reconcile safely without repeating irreversible effects.
     * Implementations unable to guarantee that idempotency must not opt in.
     */
    public function retire(AgentRuntimeContext $context): AgentRuntimeResult;
}
