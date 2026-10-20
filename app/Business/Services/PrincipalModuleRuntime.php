<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AdvisoryModuleViolation;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IRetirableAgentRuntime;
use BlueFission\Arr;
use BlueFission\Automata\LLM\Agent;
use BlueFission\Automata\LLM\Agent\Capability\AutonomyDecision;
use BlueFission\Automata\LLM\Agent\State\IAgentModule;
use BlueFission\Automata\Strategy\Routing\StrategyRouteRequest;
use Closure;
use Throwable;
use WeakMap;

/** Optional advisory endpoint; general intent routing remains host-owned. */
final class PrincipalModuleRuntime implements IRetirableAgentRuntime
{
    private static ?WeakMap $claimedModules = null;
    private Arr $profile;
    private ?Agent $agent = null;
    private ?AdvisoryAgentModule $module = null;
    private bool $executing = false;
    private bool $retiring = false;
    private bool $retired = false;
    private bool $uncertain = false;
    private bool $faulted = false;
    private ?Closure $buildModule;
    private ?Closure $authorize;

    public function __construct(private AgentDescriptor $descriptor, private string $applicationId,
        private ?string $tenantId, array $profile, Closure $buildModule, Closure $authorize)
    {
        $this->profile = Arr::make($profile);
        $this->buildModule = $buildModule;
        $this->authorize = $authorize;
    }

    public function start(AgentRuntimeContext $context): AgentRuntimeResult { return $this->lifecycle('start', 'running', $context); }
    public function suspend(AgentRuntimeContext $context): AgentRuntimeResult { return $this->lifecycle('suspend', 'suspended', $context); }
    public function resume(AgentRuntimeContext $context): AgentRuntimeResult { return $this->lifecycle('resume', 'running', $context); }

    public function stop(AgentRuntimeContext $context): AgentRuntimeResult
    {
        $denied = $this->scopeDenial('stop', $context, cleanup: true);
        if ($denied !== null) { return $denied; }
        return $this->release($context, false);
    }

    public function cancel(?string $executionId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->scopeDenial('cancel', $context) ?? AgentRuntimeResult::unavailable(
            'cancel', $this->descriptor->id(), $this->tenantId, 'running', 'agent_runtime_cancellation_unsupported');
    }

    public function retire(AgentRuntimeContext $context): AgentRuntimeResult
    {
        $denied = $this->scopeDenial('retire', $context, cleanup: true);
        return $denied ?? $this->release($context, true);
    }

    public function execute(string $executionId, array $task, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $denied = $this->scopeDenial('execute', $context);
        if ($denied !== null) { return $denied; }
        $this->executing = true;
        try {
            $request = new StrategyRouteRequest([
                'id' => $executionId,
                'subject_id' => $this->descriptor->id() . ':' . hash('sha256', Arr::make([$this->applicationId, $this->tenantId])->toJson()),
                'capability_id' => $this->profile->get('capability_id'),
                'capability_version' => $this->profile->get('capability_version'),
                'correlation_id' => $context->correlationId() ?? '',
                'input' => Arr::getPath($task, 'input'),
            ]);
            $decision = ($this->authorize)($this->descriptor, $context, new StrategyRouteRequest($request->toArray()));
            if (!$decision instanceof AutonomyDecision || $decision->allowed !== true
                || $decision->subject_id !== $request->subject_id
                || $decision->capability_id !== $request->capability_id
                || $decision->capability_version !== $request->capability_version) {
                return AgentRuntimeResult::denied('execute', $this->descriptor->id(), $this->tenantId,
                    'running', 'agent_module_authorization_denied');
            }
            if ($this->module === null) {
                $module = ($this->buildModule)($this->descriptor, $context);
                if (!$module instanceof IAgentModule) {
                    throw new AdvisoryModuleViolation('agent_module_invalid');
                }
                self::$claimedModules ??= new WeakMap();
                if (isset(self::$claimedModules[$module])) {
                    throw new AdvisoryModuleViolation('agent_module_already_bound');
                }
                self::$claimedModules[$module] = true;
                $this->module = new AdvisoryAgentModule($module);
                $this->agent = new Agent(null);
            }
            $moduleContext = [
                'applicationId' => $this->applicationId, 'agentId' => $this->descriptor->id(),
                'tenantId' => $this->tenantId, 'correlationId' => $context->correlationId(),
                'executionId' => $executionId,
            ];
            $moduleContext[$this->profile->get('input_key') ?? 'input'] = Arr::getPath($task, 'input');
            $result = $this->agent->runModule($this->module, $moduleContext)->toArray();
            if (!Arr::has(['completed', 'advisory', 'review_required'], Arr::getPath($result, 'status'), true)) {
                return AgentRuntimeResult::unavailable('execute', $this->descriptor->id(), $this->tenantId,
                    'running', 'agent_module_not_completed');
            }
            return AgentRuntimeResult::completed('execute', $this->descriptor->id(), $this->tenantId, 'running', [
                'executionAuthority' => 'none', 'reviewRequired' => true,
                'advisoryStatus' => Arr::getPath($result, 'status'), 'advice' => Arr::getPath($result, 'metadata', []),
            ], ['execution_id' => $executionId, 'correlation_id' => $context->correlationId(),
                'usage_known' => false]);
        } catch (AdvisoryModuleViolation) {
            $this->faulted = true;
            return AgentRuntimeResult::failed('execute', $this->descriptor->id(), $this->tenantId,
                'failed', 'agent_module_advisory_violation');
        } catch (Throwable) {
            $this->faulted = true;
            return AgentRuntimeResult::failed('execute', $this->descriptor->id(), $this->tenantId,
                'failed', 'agent_module_evaluation_failed');
        } finally {
            $this->executing = false;
        }
    }

    private function lifecycle(string $action, string $state, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->scopeDenial($action, $context)
            ?? AgentRuntimeResult::completed($action, $this->descriptor->id(), $this->tenantId, $state);
    }

    private function scopeDenial(string $action, AgentRuntimeContext $context, bool $cleanup = false): ?AgentRuntimeResult
    {
        $reason = match (true) {
            $context->applicationId() !== $this->applicationId || $context->tenantId() !== $this->tenantId => 'agent_runtime_scope_mismatch',
            $this->retiring => 'agent_runtime_retirement_in_progress',
            $this->uncertain => 'agent_runtime_retirement_uncertain',
            $this->executing => 'agent_execution_in_progress',
            $this->retired && (!$cleanup || $action !== 'retire') => 'agent_runtime_retired',
            $this->faulted && !$cleanup => 'agent_module_faulted',
            default => null,
        };
        return $reason === null ? null : AgentRuntimeResult::denied($action, $this->descriptor->id(),
            $context->tenantId(), 'unknown', $reason);
    }

    private function release(AgentRuntimeContext $context, bool $permanent): AgentRuntimeResult
    {
        $action = $permanent ? 'retire' : 'stop';
        $this->retiring = true;
        $this->retired = $this->retired || $permanent;
        try {
            $this->module = null;
            $this->agent = null;
            if ($permanent) {
                $this->buildModule = null;
                $this->authorize = null;
                $this->profile = Arr::make([]);
            }
            return AgentRuntimeResult::completed($action, $this->descriptor->id(), $this->tenantId,
                $permanent ? 'retired' : 'stopped');
        } catch (Throwable) {
            $this->uncertain = $this->retired = true;
            return AgentRuntimeResult::failed($action, $this->descriptor->id(), $this->tenantId,
                'unknown', 'agent_runtime_retirement_uncertain');
        } finally {
            $this->retiring = false;
        }
    }
}
