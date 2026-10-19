<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use BlueFission\Arr;
use BlueFission\Automata\LLM\Agent\Capability\AutonomyDecision;
use BlueFission\Automata\Strategy\Routing\StrategyRouteRequest;
use BlueFission\Automata\Strategy\Routing\StrategyRouteResult;
use BlueFission\Automata\Strategy\Routing\StrategyRouter;
use BlueFission\Automata\Strategy\Routing\StrategyUsage;
use Closure;
use Throwable;

/** Synchronous, side-effect-free routing; persisted lifecycle belongs to composition. */
final class AutomataStrategyRuntime implements IAgentRuntime
{
    private Arr $policy;
    private bool $executing = false;

    public function __construct(
        private AgentDescriptor $descriptor,
        private ?string $tenantId,
        array $policy,
        private StrategyRouter $router,
        private Closure $authorize
    ) {
        $this->policy = Arr::make($policy);
    }

    public function start(AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->lifecycle('start', 'running', $context);
    }

    public function suspend(AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->lifecycle('suspend', 'suspended', $context);
    }

    public function resume(AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->lifecycle('resume', 'running', $context);
    }

    public function stop(AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->lifecycle('stop', 'stopped', $context);
    }

    public function cancel(?string $executionId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->scopeDenial('cancel', $context) ?? AgentRuntimeResult::unavailable(
            'cancel', $this->descriptor->id(), $this->tenantId, 'running',
            'agent_runtime_cancellation_unsupported'
        );
    }

    public function execute(string $executionId, array $task, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $denied = $this->scopeDenial('execute', $context);
        if ($denied !== null) {
            return $denied;
        }
        if ($this->executing) {
            return AgentRuntimeResult::denied('execute', $this->descriptor->id(), $this->tenantId,
                'running', 'agent_execution_in_progress');
        }
        $this->executing = true;
        try {
            $request = new StrategyRouteRequest([
                'id' => $executionId,
                'subject_id' => $this->descriptor->id() . ':' . hash('sha256',
                    $this->tenantId === null ? 'application' : 'tenant:' . $this->tenantId),
                'capability_id' => (string) $this->policy->get('capability_id'),
                'capability_version' => (string) $this->policy->get('capability_version'),
                'candidates' => (array) $this->policy->get('candidates'),
                'limits' => (array) $this->policy->get('limits'),
                'allowed_modes' => $this->policy->get('allowed_modes') ?? ['deterministic'],
                'deterministic_preferred' => true,
                'selection_policy' => $this->policy->get('selection_policy') ?? 'declared',
                'escalation_policy' => $this->policy->get('escalation_policy') ?? 'none',
                'context_key' => (string) $this->policy->get('context_key'),
                'input' => Arr::getPath($task, 'input'),
                'correlation_id' => $context->correlationId() ?? '',
            ]);
            // The authorizer receives a detached request; it cannot rewrite route policy.
            $authorization = ($this->authorize)(
                $this->descriptor, $context, new StrategyRouteRequest($request->toArray())
            );
            if (!$authorization instanceof AutonomyDecision) {
                return AgentRuntimeResult::denied('execute', $this->descriptor->id(), $this->tenantId,
                    'running', 'agent_strategy_authorization_unavailable');
            }
            $route = $this->router->route($request, $authorization);
            $status = match ($route->status) {
                StrategyRouteResult::STATUS_COMPLETED => AgentRuntimeResult::COMPLETED,
                StrategyRouteResult::STATUS_DENIED => AgentRuntimeResult::DENIED,
                StrategyRouteResult::STATUS_EXHAUSTED => AgentRuntimeResult::UNAVAILABLE,
                default => AgentRuntimeResult::FAILED,
            };
            $usage = new StrategyUsage((array) $route->usage);
            $selected = $route->selected_strategy;
            $selection = Arr::is($selected) ? [
                'id' => (string) Arr::getPath($selected, 'id'),
                'version' => (string) Arr::getPath($selected, 'version'),
                'mode' => (string) Arr::getPath($selected, 'mode'),
            ] : null;

            return new AgentRuntimeResult(
                $route->completed(), $status, 'execute', $this->descriptor->id(), $this->tenantId,
                'running', $route->completed() ? $route->output : null,
                $route->completed() ? [] : ['agent_strategy_route_' . $status],
                [['operation' => 'strategy.route', 'status' => $status,
                    'attempt_count' => Arr::make((array) $route->attempts)->count()]],
                ['execution_id' => $executionId, 'correlation_id' => $context->correlationId(),
                    'usage' => $usage->toArray(), 'selected_strategy' => $selection,
                    'escalated' => (bool) $route->escalated]
            );
        } catch (Throwable) {
            return AgentRuntimeResult::failed('execute', $this->descriptor->id(), $this->tenantId,
                'running', 'agent_strategy_runtime_failed')
                ->withMetadata(['execution_id' => $executionId, 'correlation_id' => $context->correlationId()]);
        } finally {
            $this->executing = false;
        }
    }

    private function lifecycle(string $action, string $state, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->scopeDenial($action, $context) ?? ($this->executing
            ? AgentRuntimeResult::denied($action, $this->descriptor->id(), $this->tenantId,
                'running', 'agent_execution_in_progress')
            : AgentRuntimeResult::completed($action, $this->descriptor->id(), $this->tenantId, $state));
    }

    private function scopeDenial(string $action, AgentRuntimeContext $context): ?AgentRuntimeResult
    {
        return $context->tenantId() === $this->tenantId ? null : AgentRuntimeResult::denied(
            $action, $this->descriptor->id(), $context->tenantId(), 'unknown', 'agent_runtime_scope_mismatch'
        );
    }
}
