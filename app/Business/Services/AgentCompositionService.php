<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateStore;
use BlueFission\Arr;
use BlueFission\Services\Service;
use BlueFission\Str;
use Throwable;

final class AgentCompositionService extends Service
{
    public const REGISTERED = 'registered';
    public const RUNNING = 'running';
    public const SUSPENDED = 'suspended';
    public const STOPPED = 'stopped';
    public const FAILED = 'failed';

    private Arr $descriptors;
    private Arr $runtimes;

    public function __construct(
        private AgentCapabilityMapResolver $resolver,
        private IAgentRuntimeFactory $factory,
        private IAgentRuntimeStateStore $states
    ) {
        parent::__construct();
        $this->descriptors = Arr::make([]);
        $this->runtimes = Arr::make([]);
    }

    public function registerMap(AgentCapabilityMap $map): AgentRuntimeResult
    {
        $registered = 0;
        Arr::make($map->agents())->each(function ($descriptor) use (&$registered): void {
            if ($descriptor instanceof AgentDescriptor) {
                $this->descriptors->set($descriptor->id(), $descriptor);
                $registered++;
            }
        });
        $this->resolver->register($map);

        return AgentRuntimeResult::completed(
            'register',
            $map->owner(),
            null,
            self::REGISTERED,
            ['registered' => $registered]
        );
    }

    public function start(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $authorized = $this->authorize('start', $agentId, $context);
        if ($authorized !== null) {
            return $authorized;
        }

        $current = $this->state($agentId, $context);
        if ($current === self::RUNNING) {
            return $this->idempotent('start', $agentId, $context, self::RUNNING);
        }
        if ($current === self::SUSPENDED) {
            return AgentRuntimeResult::denied('start', $agentId, $context->tenantId(), $current, 'agent_resume_required');
        }

        return $this->invokeLifecycle('start', $agentId, $context, self::RUNNING);
    }

    public function suspend(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->transition('suspend', $agentId, $context, [self::RUNNING], self::SUSPENDED);
    }

    public function resume(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->transition('resume', $agentId, $context, [self::SUSPENDED], self::RUNNING);
    }

    public function stop(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->transition(
            'stop',
            $agentId,
            $context,
            [self::RUNNING, self::SUSPENDED, self::FAILED],
            self::STOPPED
        );
    }

    public function cancel(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $authorized = $this->authorize('cancel', $agentId, $context);
        if ($authorized !== null) {
            return $authorized;
        }

        $current = $this->state($agentId, $context);
        $persisted = $this->states->get($agentId, $context->tenantId()) ?? [];
        if (Arr::getPath($persisted, 'cancelled') === true) {
            return $this->idempotent('cancel', $agentId, $context, $current);
        }
        if (!Arr::make([self::RUNNING, self::SUSPENDED])->contains($current)) {
            return AgentRuntimeResult::denied('cancel', $agentId, $context->tenantId(), $current, 'agent_not_active');
        }

        $result = $this->invoke('cancel', $agentId, $context)->forScope(
            'cancel',
            $agentId,
            $context->tenantId(),
            $current
        );
        if ($result->ok()) {
            $latest = $this->states->get($agentId, $context->tenantId()) ?? [];
            $latestState = (string) Arr::getPath($latest, 'state', $current);
            $this->states->put($agentId, $context->tenantId(), Arr::merge($latest, [
                'status' => $result->status(),
                'cancelled' => true,
                'cancellation_correlation_id' => $context->correlationId(),
                'diagnostics' => $result->diagnostics(),
            ]));

            return $result->forScope('cancel', $agentId, $context->tenantId(), $latestState);
        }

        return $result;
    }

    public function execute(string $agentId, array $task, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $authorized = $this->authorize('execute', $agentId, $context);
        if ($authorized !== null) {
            return $this->correlate($authorized, $context);
        }

        $current = $this->state($agentId, $context);
        if ($current !== self::RUNNING) {
            return $this->correlate(
                AgentRuntimeResult::denied(
                    'execute',
                    $agentId,
                    $context->tenantId(),
                    $current,
                    'agent_not_running'
                ),
                $context
            );
        }

        try {
            if (!$this->runtimeAvailable($agentId, $context)) {
                return $this->correlate(
                    AgentRuntimeResult::unavailable(
                        'execute',
                        $agentId,
                        $context->tenantId(),
                        $current,
                        'agent_runtime_unavailable'
                    ),
                    $context
                );
            }

            $runtime = $this->runtime($agentId, $context);
            $persisted = $this->states->get($agentId, $context->tenantId()) ?? [];
            $this->states->put($agentId, $context->tenantId(), Arr::merge($persisted, [
                'state' => self::RUNNING,
                'cancelled' => false,
                'cancellation_correlation_id' => null,
                'correlation_id' => $context->correlationId(),
            ]));
            $result = $runtime
                ->execute($task, $context)
                ->withMetadata(['correlation_id' => $context->correlationId()]);
            $latestState = $this->state($agentId, $context);
            if ($result->ok()) {
                $persisted = $this->states->get($agentId, $context->tenantId()) ?? [];
                $this->states->put($agentId, $context->tenantId(), Arr::merge($persisted, [
                    'status' => $result->status(),
                    'diagnostics' => $result->diagnostics(),
                    'correlation_id' => $context->correlationId(),
                ]));
            }

            return $result->forScope('execute', $agentId, $context->tenantId(), $latestState);
        } catch (Throwable $exception) {
            return $this->correlate(
                AgentRuntimeResult::failed(
                    'execute',
                    $agentId,
                    $context->tenantId(),
                    $this->state($agentId, $context),
                    $exception->getMessage()
                ),
                $context
            );
        }
    }

    private function transition(
        string $action,
        string $agentId,
        AgentRuntimeContext $context,
        array $from,
        string $target
    ): AgentRuntimeResult {
        $authorized = $this->authorize($action, $agentId, $context);
        if ($authorized !== null) {
            return $authorized;
        }

        $current = $this->state($agentId, $context);
        if ($current === $target) {
            return $this->idempotent($action, $agentId, $context, $target);
        }
        if (!Arr::make($from)->contains($current)) {
            return AgentRuntimeResult::denied($action, $agentId, $context->tenantId(), $current, 'agent_transition_denied');
        }

        return $this->invokeLifecycle($action, $agentId, $context, $target);
    }

    private function invokeLifecycle(
        string $action,
        string $agentId,
        AgentRuntimeContext $context,
        string $target
    ): AgentRuntimeResult {
        $current = $this->state($agentId, $context);
        $result = $this->invoke($action, $agentId, $context)->forScope(
            $action,
            $agentId,
            $context->tenantId(),
            $target
        );
        $state = $result->ok()
            ? $target
            : ($result->status() === AgentRuntimeResult::UNAVAILABLE ? $current : self::FAILED);
        $persisted = $this->states->get($agentId, $context->tenantId()) ?? [];
        $this->states->put($agentId, $context->tenantId(), Arr::merge($persisted, [
            'state' => $state,
            'status' => $result->status(),
            'diagnostics' => $result->diagnostics(),
            'correlation_id' => $context->correlationId(),
        ]));

        return $result->forScope($action, $agentId, $context->tenantId(), $state);
    }

    private function invoke(string $action, string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        if (!$this->runtimeAvailable($agentId, $context)) {
            return AgentRuntimeResult::unavailable(
                $action,
                $agentId,
                $context->tenantId(),
                $this->state($agentId, $context),
                'agent_runtime_unavailable'
            );
        }

        try {
            $runtime = $this->runtime($agentId, $context);

            return $runtime->{$action}($context);
        } catch (Throwable $exception) {
            return AgentRuntimeResult::failed(
                $action,
                $agentId,
                $context->tenantId(),
                self::FAILED,
                $exception->getMessage()
            );
        }
    }

    private function runtime(string $agentId, AgentRuntimeContext $context): IAgentRuntime
    {
        $key = $this->key($agentId, $context->tenantId());
        $runtime = $this->runtimes->get($key);
        if ($runtime instanceof IAgentRuntime) {
            return $runtime;
        }

        $descriptor = $this->descriptor($agentId);
        if (!$descriptor instanceof AgentDescriptor || !$this->factory->available($descriptor, $context)) {
            throw new \RuntimeException('agent_runtime_unavailable');
        }

        $runtime = $this->factory->create($descriptor, $context);
        $this->runtimes->set($key, $runtime);

        return $runtime;
    }

    private function runtimeAvailable(string $agentId, AgentRuntimeContext $context): bool
    {
        if ($this->runtimes->get($this->key($agentId, $context->tenantId())) instanceof IAgentRuntime) {
            return true;
        }

        $descriptor = $this->descriptor($agentId);

        return $descriptor instanceof AgentDescriptor && $this->factory->available($descriptor, $context);
    }

    private function authorize(
        string $action,
        string $agentId,
        AgentRuntimeContext $context
    ): ?AgentRuntimeResult {
        $descriptor = $this->descriptor($agentId);
        $state = $this->state($agentId, $context);
        if (!$descriptor instanceof AgentDescriptor) {
            return AgentRuntimeResult::denied($action, $agentId, $context->tenantId(), $state, 'agent_not_registered');
        }
        if ($descriptor->owner() !== 'application' && $context->tenantId() === null) {
            return AgentRuntimeResult::denied($action, $agentId, null, $state, 'agent_tenant_required');
        }

        $resolved = $this->resolver->resolve(
            $agentId,
            $context->activeAddOns(),
            $context->lifecycleStates(),
            $context->capabilities(),
            $context->tenantId()
        );
        $allowed = Arr::make($resolved->decisions())->filter(fn ($decision): bool =>
            Arr::getPath((array) $decision, 'decision') === 'allow'
            && Arr::getPath((array) $decision, 'reason') === 'agent_local_tools'
        )->isNotEmpty();
        if (!$allowed) {
            $lastDecision = Arr::make($resolved->decisions())->reverse()->get(0);
            $reason = Arr::getPath((array) $lastDecision, 'reason', 'agent_policy_denied');

            return AgentRuntimeResult::denied(
                $action,
                $agentId,
                $context->tenantId(),
                $state,
                (string) $reason
            );
        }

        return null;
    }

    private function descriptor(string $agentId): ?AgentDescriptor
    {
        $descriptor = $this->descriptors->get($agentId);

        return $descriptor instanceof AgentDescriptor ? $descriptor : null;
    }

    private function state(string $agentId, AgentRuntimeContext $context): string
    {
        return (string) Arr::getPath(
            (array) $this->states->get($agentId, $context->tenantId()),
            'state',
            self::REGISTERED
        );
    }

    private function idempotent(
        string $action,
        string $agentId,
        AgentRuntimeContext $context,
        string $state
    ): AgentRuntimeResult {
        return AgentRuntimeResult::completed(
            $action,
            $agentId,
            $context->tenantId(),
            $state,
            metadata: ['idempotent' => true]
        );
    }

    private function key(string $agentId, ?string $tenantId): string
    {
        return Str::make(Str::isNotEmpty((string) $tenantId) ? 'tenant:' . $tenantId : 'scope:application')
            ->append('::')
            ->append($agentId)
            ->val();
    }

    private function correlate(AgentRuntimeResult $result, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $result->withMetadata(['correlation_id' => $context->correlationId()]);
    }
}
