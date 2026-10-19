<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use BlueFission\Arr;
use BlueFission\Automata\Strategy\Routing\StrategyRouter;
use Closure;
use RuntimeException;

/** Host composition only: profile data is configuration, never task input. */
final class AutomataStrategyRuntimeFactory implements IAgentRuntimeFactory
{
    private Arr $profiles;
    private Closure $buildRouter;
    private Closure $authorize;

    public function __construct(array $profiles, callable $buildRouter, callable $authorize)
    {
        $this->profiles = Arr::make($profiles);
        $this->buildRouter = Closure::fromCallable($buildRouter);
        $this->authorize = Closure::fromCallable($authorize);
    }

    public function available(AgentDescriptor $descriptor, AgentRuntimeContext $context): bool
    {
        return Arr::is($this->profiles->get($descriptor->profile()))
            && $descriptor->mode() !== 'disabled'
            && ($descriptor->owner() === 'application' || $context->tenantId() !== null)
            && ($descriptor->mode() !== 'central' || $descriptor->owner() === 'application')
            && class_exists(StrategyRouter::class);
    }

    public function create(AgentDescriptor $descriptor, AgentRuntimeContext $context): IAgentRuntime
    {
        if (!$this->available($descriptor, $context)) {
            throw new RuntimeException('agent_runtime_unavailable');
        }
        $router = ($this->buildRouter)($descriptor, $context);
        if (!$router instanceof StrategyRouter) {
            throw new RuntimeException('agent_strategy_router_invalid');
        }

        return new AutomataStrategyRuntime(
            $descriptor,
            $context->tenantId(),
            (array) $this->profiles->get($descriptor->profile()),
            $router,
            $this->authorize
        );
    }
}
