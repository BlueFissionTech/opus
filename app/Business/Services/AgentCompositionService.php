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
            return $this->stableIdempotent('start', $agentId, $context, self::RUNNING);
        }
        if ($current === self::SUSPENDED) {
            return AgentRuntimeResult::denied('start', $agentId, $context->tenantId(), $current, 'agent_resume_required');
        }

        return $this->invokeLifecycle('start', $agentId, $context, $current, self::RUNNING);
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
        $coveredExecution = Arr::getPath($persisted, 'execution_id');
        if (Arr::getPath($persisted, 'cancelled') === true) {
            return $this->idempotent('cancel', $agentId, $context, $current);
        }
        if (!Arr::make([self::RUNNING, self::SUSPENDED])->contains($current)) {
            return AgentRuntimeResult::denied('cancel', $agentId, $context->tenantId(), $current, 'agent_not_active');
        }

        $existingClaim = Arr::getPath($persisted, 'cancellation_id');
        if (Str::isNotEmpty((string) $existingClaim)) {
            return AgentRuntimeResult::denied(
                'cancel',
                $agentId,
                $context->tenantId(),
                $current,
                'agent_cancellation_in_progress'
            );
        }
        $cancellationId = $this->operationId();
        $claimed = $this->states->compareAndPut(
            $agentId,
            $context->tenantId(),
            [
                'state' => $current,
                'execution_id' => $coveredExecution,
                'cancelled' => Arr::getPath($persisted, 'cancelled'),
                'cancellation_id' => $existingClaim,
            ],
            [
                'cancellation_id' => $cancellationId,
                'cancellation_execution_id' => $coveredExecution,
                'cancellation_expires_at' => null,
            ]
        );
        if (!$claimed) {
            return AgentRuntimeResult::denied(
                'cancel',
                $agentId,
                $context->tenantId(),
                $this->state($agentId, $context),
                'agent_cancellation_in_progress'
            );
        }

        try {
            $result = $this->invokeCancellation($agentId, $coveredExecution, $context)->forScope(
                'cancel',
                $agentId,
                $context->tenantId(),
                $current
            );
            if ($result->ok()) {
                $latest = $this->states->get($agentId, $context->tenantId()) ?? [];
                $latestState = (string) Arr::getPath($latest, 'state', $current);
                if (Arr::getPath($latest, 'execution_id') !== $coveredExecution) {
                    $this->states->compareAndPut(
                        $agentId,
                        $context->tenantId(),
                        ['cancellation_id' => $cancellationId],
                        [
                            'cancellation_id' => null,
                            'cancellation_execution_id' => null,
                            'cancellation_expires_at' => null,
                        ]
                    );
                    return $result
                        ->withMetadata(['cancellation_superseded' => true])
                        ->forScope('cancel', $agentId, $context->tenantId(), $latestState);
                }
                $this->states->compareAndPut($agentId, $context->tenantId(), [
                    'cancellation_id' => $cancellationId,
                ], [
                    'status' => $result->status(),
                    'cancelled' => true,
                    'cancellation_correlation_id' => $context->correlationId(),
                    'cancellation_id' => null,
                    'cancellation_execution_id' => null,
                    'cancellation_expires_at' => null,
                    'diagnostics' => $result->diagnostics(),
                ]);

                return $result->forScope('cancel', $agentId, $context->tenantId(), $latestState);
            }

            $this->states->compareAndPut(
                $agentId,
                $context->tenantId(),
                ['cancellation_id' => $cancellationId],
                [
                    'cancellation_id' => null,
                    'cancellation_execution_id' => null,
                    'cancellation_expires_at' => null,
                ]
            );

            return $result;
        } catch (Throwable $exception) {
            $this->states->compareAndPut(
                $agentId,
                $context->tenantId(),
                ['cancellation_id' => $cancellationId],
                [
                    'cancellation_id' => null,
                    'cancellation_execution_id' => null,
                    'cancellation_expires_at' => null,
                ]
            );

            return AgentRuntimeResult::failed(
                'cancel',
                $agentId,
                $context->tenantId(),
                $current,
                $exception->getMessage()
            );
        }
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
            $transitionId = Arr::getPath($persisted, 'transition_id');
            if (Str::isNotEmpty((string) $transitionId) && $this->claimIsLive((string) $transitionId)) {
                return $this->correlate(
                    AgentRuntimeResult::denied(
                        'execute',
                        $agentId,
                        $context->tenantId(),
                        $current,
                        'agent_transition_in_progress'
                    ),
                    $context
                );
            }
            $cancellationId = Arr::getPath($persisted, 'cancellation_id');
            $executionId = $this->operationId();
            $accepted = $this->states->compareAndPut($agentId, $context->tenantId(), [
                'state' => self::RUNNING,
                'transition_id' => $transitionId,
                'cancellation_id' => $cancellationId,
            ], [
                'state' => self::RUNNING,
                'execution_id' => $executionId,
                'cancelled' => false,
                'cancellation_correlation_id' => null,
                'cancellation_id' => null,
                'cancellation_execution_id' => null,
                'cancellation_expires_at' => null,
                'correlation_id' => $context->correlationId(),
                'transition_id' => null,
                'transition_action' => null,
                'transition_correlation_id' => null,
                'transition_expires_at' => null,
            ]);
            if (!$accepted) {
                $latestRecord = $this->states->get($agentId, $context->tenantId()) ?? [];
                $latest = (string) Arr::getPath($latestRecord, 'state', self::REGISTERED);
                $latestCancellation = (string) Arr::getPath($latestRecord, 'cancellation_id');
                $reason = Str::isNotEmpty($latestCancellation)
                    ? 'agent_cancellation_in_progress'
                    : ($latest === self::RUNNING ? 'agent_transition_in_progress' : 'agent_not_running');

                return $this->correlate(
                    AgentRuntimeResult::denied(
                        'execute',
                        $agentId,
                        $context->tenantId(),
                        $latest,
                        $reason
                    ),
                    $context
                );
            }
            $result = $runtime
                ->execute($executionId, $task, $context)
                ->withMetadata([
                    'correlation_id' => $context->correlationId(),
                    'execution_id' => $executionId,
                ]);
            if ($result->ok()) {
                $this->states->compareAndPut($agentId, $context->tenantId(), [
                    'execution_id' => $executionId,
                ], [
                    'status' => $result->status(),
                    'diagnostics' => $result->diagnostics(),
                    'correlation_id' => $context->correlationId(),
                ]);
            }
            $latestState = $this->state($agentId, $context);

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
            return $this->stableIdempotent($action, $agentId, $context, $target);
        }
        if (!Arr::make($from)->contains($current)) {
            return AgentRuntimeResult::denied($action, $agentId, $context->tenantId(), $current, 'agent_transition_denied');
        }

        return $this->invokeLifecycle($action, $agentId, $context, $current, $target);
    }

    private function invokeLifecycle(
        string $action,
        string $agentId,
        AgentRuntimeContext $context,
        string $source,
        string $target
    ): AgentRuntimeResult {
        $transitionId = $this->operationId();
        $persisted = $this->states->get($agentId, $context->tenantId());
        $existingTransition = Arr::getPath((array) $persisted, 'transition_id');
        if (Str::isNotEmpty((string) $existingTransition) && $this->claimIsLive((string) $existingTransition)) {
            return AgentRuntimeResult::denied(
                $action,
                $agentId,
                $context->tenantId(),
                $source,
                'agent_transition_in_progress'
            );
        }
        try {
            $operationLock = $this->acquireOperationLock($transitionId);
        } catch (Throwable $exception) {
            return AgentRuntimeResult::failed(
                $action,
                $agentId,
                $context->tenantId(),
                $source,
                $exception->getMessage()
            );
        }
        try {
            $claimed = $this->states->compareAndPut(
                $agentId,
                $context->tenantId(),
                [
                    'state' => $persisted === null ? null : $source,
                    'transition_id' => $existingTransition,
                ],
                [
                    'state' => $source,
                    'transition_id' => $transitionId,
                    'transition_action' => $action,
                    'transition_correlation_id' => $context->correlationId(),
                    'transition_expires_at' => null,
                ]
            );
            if (!$claimed) {
                return AgentRuntimeResult::denied(
                    $action,
                    $agentId,
                    $context->tenantId(),
                    $this->state($agentId, $context),
                    'agent_transition_in_progress'
                );
            }
            $result = $this->invoke($action, $agentId, $context)->forScope(
                $action,
                $agentId,
                $context->tenantId(),
                $target
            );
            $state = $result->ok()
                ? $target
                : ($result->status() === AgentRuntimeResult::UNAVAILABLE ? $source : self::FAILED);
            $updated = $this->states->compareAndPut($agentId, $context->tenantId(), [
                'transition_id' => $transitionId,
            ], [
                'state' => $state,
                'status' => $result->status(),
                'diagnostics' => $result->diagnostics(),
                'correlation_id' => $context->correlationId(),
                'transition_id' => null,
                'transition_action' => null,
                'transition_correlation_id' => null,
                'transition_expires_at' => null,
            ]);
            if (!$updated) {
                return $result
                    ->withMetadata(['transition_superseded' => true])
                    ->forScope($action, $agentId, $context->tenantId(), $this->state($agentId, $context));
            }

            return $result->forScope($action, $agentId, $context->tenantId(), $state);
        } finally {
            $this->releaseOperationLock($operationLock, $transitionId);
        }
    }

    private function invokeCancellation(
        string $agentId,
        ?string $executionId,
        AgentRuntimeContext $context
    ): AgentRuntimeResult {
        if (!$this->runtimeAvailable($agentId, $context)) {
            return AgentRuntimeResult::unavailable(
                'cancel',
                $agentId,
                $context->tenantId(),
                $this->state($agentId, $context),
                'agent_runtime_unavailable'
            );
        }

        return $this->runtime($agentId, $context)->cancel($executionId, $context);
    }

    private function invoke(string $action, string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        try {
            if (!$this->runtimeAvailable($agentId, $context)) {
                return AgentRuntimeResult::unavailable(
                    $action,
                    $agentId,
                    $context->tenantId(),
                    $this->state($agentId, $context),
                    'agent_runtime_unavailable'
                );
            }
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
        $decisions = Arr::make($resolved->decisions());
        $denied = $decisions->filter(fn ($decision): bool =>
            Arr::getPath((array) $decision, 'decision') === 'deny'
        )->values();
        if ($denied->isNotEmpty()) {
            $reason = Arr::getPath((array) $denied->reverse()->get(0), 'reason', 'agent_policy_denied');

            return AgentRuntimeResult::denied(
                $action,
                $agentId,
                $context->tenantId(),
                $state,
                (string) $reason
            );
        }

        $allowed = $decisions->filter(fn ($decision): bool =>
            Arr::getPath((array) $decision, 'decision') === 'allow'
            && Arr::getPath((array) $decision, 'reason') === 'agent_local_tools'
        )->isNotEmpty();
        if (!$allowed) {
            $lastDecision = $decisions->reverse()->get(0);
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

    private function stableIdempotent(
        string $action,
        string $agentId,
        AgentRuntimeContext $context,
        string $state
    ): AgentRuntimeResult {
        $persisted = $this->states->get($agentId, $context->tenantId()) ?? [];
        $transitionId = Arr::getPath($persisted, 'transition_id');
        if (Str::isNotEmpty((string) $transitionId)) {
            if ($this->claimIsLive((string) $transitionId)) {
                return AgentRuntimeResult::denied(
                    $action,
                    $agentId,
                    $context->tenantId(),
                    $state,
                    'agent_transition_in_progress'
                );
            }
            $recovered = $this->states->compareAndPut(
                $agentId,
                $context->tenantId(),
                ['transition_id' => $transitionId],
                [
                    'transition_id' => null,
                    'transition_action' => null,
                    'transition_correlation_id' => null,
                    'transition_expires_at' => null,
                ]
            );
            if (!$recovered) {
                return AgentRuntimeResult::denied(
                    $action,
                    $agentId,
                    $context->tenantId(),
                    $this->state($agentId, $context),
                    'agent_transition_in_progress'
                );
            }
        }

        if (!$this->states->compareAndPut(
            $agentId,
            $context->tenantId(),
            ['state' => $state, 'transition_id' => null],
            []
        )) {
            return AgentRuntimeResult::denied(
                $action,
                $agentId,
                $context->tenantId(),
                $this->state($agentId, $context),
                'agent_transition_in_progress'
            );
        }

        return $this->idempotent($action, $agentId, $context, $state);
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

    private function operationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function acquireOperationLock(string $operationId): mixed
    {
        $handle = fopen($this->operationLockPath($operationId), 'c+');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new \RuntimeException('Agent runtime operation lock is unavailable.');
        }

        return $handle;
    }

    private function claimIsLive(string $operationId): bool
    {
        $handle = fopen($this->operationLockPath($operationId), 'c+');
        if ($handle === false) {
            return true;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return true;
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        return false;
    }

    private function releaseOperationLock(mixed $handle, string $operationId): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $path = $this->operationLockPath($operationId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function operationLockPath(string $operationId): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'opus-agent-operation-'
            . $operationId
            . '.lock';
    }
}
