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
    private const CLAIM_LEASE_SECONDS = 300;

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
        private IAgentRuntimeStateStore $states,
        private int $claimLeaseSeconds = self::CLAIM_LEASE_SECONDS
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
                $existing = $this->descriptors->get($descriptor->id());
                if ($existing instanceof AgentDescriptor
                    && $this->descriptorContract($existing) !== $this->descriptorContract($descriptor)
                ) {
                    $this->evictRuntimes($descriptor->id());
                }
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

    private function descriptorContract(AgentDescriptor $descriptor): array
    {
        return [
            'owner' => $descriptor->owner(),
            'mode' => $descriptor->mode(),
            'description' => $descriptor->description(),
            'profile' => $descriptor->profile(),
            'tools' => $descriptor->tools(),
            'imports' => $descriptor->imports(),
            'exports' => $descriptor->exports(),
            'permissions' => $descriptor->permissions(),
            'lifecycle_states' => $descriptor->lifecycleStates(),
        ];
    }

    private function evictRuntimes(string $agentId): void
    {
        $suffix = '::' . $agentId;
        $this->runtimes->keys()->each(function ($key) use ($suffix): void {
            if (Str::make((string) $key)->endsWith($suffix)) {
                $this->runtimes->delete((string) $key);
            }
        });
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

        try {
            return $this->states->synchronized(
                $this->claimScope('cancellation', $agentId, $context->tenantId()),
                fn (): AgentRuntimeResult => $this->cancelSynchronized($agentId, $context)
            );
        } catch (Throwable $exception) {
            if ($this->claimGuardUnavailable($exception)) {
                return AgentRuntimeResult::denied(
                    'cancel',
                    $agentId,
                    $context->tenantId(),
                    $this->state($agentId, $context),
                    'agent_cancellation_in_progress'
                );
            }

            return AgentRuntimeResult::failed(
                'cancel',
                $agentId,
                $context->tenantId(),
                $this->state($agentId, $context),
                $exception->getMessage()
            );
        }
    }

    private function cancelSynchronized(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $current = $this->state($agentId, $context);
        $persisted = $this->states->get($agentId, $context->tenantId()) ?? [];
        $coveredExecution = Arr::getPath($persisted, 'active_execution_id')
            ?? Arr::getPath($persisted, 'execution_id');
        if (Arr::getPath($persisted, 'cancelled') === true) {
            return $this->idempotent('cancel', $agentId, $context, $current);
        }
        if (!Arr::make([self::RUNNING, self::SUSPENDED])->contains($current)) {
            return AgentRuntimeResult::denied('cancel', $agentId, $context->tenantId(), $current, 'agent_not_active');
        }

        $existingClaim = Arr::getPath($persisted, 'cancellation_id');
        $existingClaimExpiresAt = (int) Arr::getPath($persisted, 'cancellation_expires_at', 0);
        if (Str::isNotEmpty((string) $existingClaim)
            && $this->claimIsLive($existingClaimExpiresAt)
        ) {
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
                'cancellation_expires_at' => Arr::getPath($persisted, 'cancellation_expires_at'),
            ],
            [
                'cancellation_id' => $cancellationId,
                'cancellation_execution_id' => $coveredExecution,
                'cancellation_expires_at' => $this->leaseExpiresAt(),
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
                $completionExpected = [
                    'cancellation_id' => $cancellationId,
                ];
                $completion = [
                    'status' => $result->status(),
                    'cancelled' => true,
                    'cancellation_correlation_id' => $context->correlationId(),
                    'cancellation_id' => null,
                    'cancellation_execution_id' => null,
                    'cancellation_expires_at' => null,
                    'diagnostics' => $result->diagnostics(),
                ];
                if (Str::isNotEmpty((string) $coveredExecution)
                    && Arr::getPath($latest, 'active_execution_id') === $coveredExecution
                ) {
                    $completionExpected['active_execution_id'] = $coveredExecution;
                    $completion['active_execution_id'] = null;
                }
                $this->states->compareAndPut(
                    $agentId,
                    $context->tenantId(),
                    $completionExpected,
                    $completion
                );

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

            $persisted = $this->states->get($agentId, $context->tenantId()) ?? [];
            $transitionId = Arr::getPath($persisted, 'transition_id');
            if (Str::isNotEmpty((string) $transitionId)) {
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
            $activeExecutionId = Arr::getPath($persisted, 'active_execution_id');
            if (Str::isNotEmpty((string) $activeExecutionId)) {
                return $this->correlate(
                    AgentRuntimeResult::denied(
                        'execute',
                        $agentId,
                        $context->tenantId(),
                        $current,
                        'agent_execution_in_progress'
                    ),
                    $context
                );
            }
            $runtime = $this->runtime($agentId, $context);
            $cancellationId = Arr::getPath($persisted, 'cancellation_id');
            $executionId = $this->operationId();
            $accepted = $this->states->compareAndPut($agentId, $context->tenantId(), [
                'state' => self::RUNNING,
                'transition_id' => $transitionId,
                'cancellation_id' => $cancellationId,
                'active_execution_id' => $activeExecutionId,
            ], [
                'state' => self::RUNNING,
                'execution_id' => $executionId,
                'active_execution_id' => $executionId,
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
                $latestExecution = (string) Arr::getPath($latestRecord, 'active_execution_id');
                if (Str::isNotEmpty($latestCancellation)) {
                    $reason = 'agent_cancellation_in_progress';
                } elseif (Str::isNotEmpty($latestExecution)) {
                    $reason = 'agent_execution_in_progress';
                } else {
                    $reason = $latest === self::RUNNING
                        ? 'agent_transition_in_progress'
                        : 'agent_not_running';
                }

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
            $completion = [
                'active_execution_id' => null,
            ];
            if ($result->ok()) {
                $completion = Arr::merge($completion, [
                    'status' => $result->status(),
                    'diagnostics' => $result->diagnostics(),
                    'correlation_id' => $context->correlationId(),
                ]);
            }
            $this->states->compareAndPut($agentId, $context->tenantId(), [
                'active_execution_id' => $executionId,
            ], $completion);
            $latestState = $this->state($agentId, $context);

            return $result->forScope('execute', $agentId, $context->tenantId(), $latestState);
        } catch (Throwable $exception) {
            if (isset($executionId)) {
                $this->states->compareAndPut($agentId, $context->tenantId(), [
                    'active_execution_id' => $executionId,
                ], [
                    'active_execution_id' => null,
                ]);
            }
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
        try {
            return $this->states->synchronized(
                $this->claimScope('lifecycle', $agentId, $context->tenantId()),
                fn (): AgentRuntimeResult => $this->invokeLifecycleSynchronized(
                    $action,
                    $agentId,
                    $context,
                    $source,
                    $target
                )
            );
        } catch (Throwable $exception) {
            if ($this->claimGuardUnavailable($exception)) {
                return AgentRuntimeResult::denied(
                    $action,
                    $agentId,
                    $context->tenantId(),
                    $this->state($agentId, $context),
                    'agent_transition_in_progress'
                );
            }

            return AgentRuntimeResult::failed(
                $action,
                $agentId,
                $context->tenantId(),
                $this->state($agentId, $context),
                $exception->getMessage()
            );
        }
    }

    private function invokeLifecycleSynchronized(
        string $action,
        string $agentId,
        AgentRuntimeContext $context,
        string $source,
        string $target
    ): AgentRuntimeResult {
        $transitionId = $this->operationId();
        $persisted = $this->states->get($agentId, $context->tenantId());
        $existingTransition = Arr::getPath((array) $persisted, 'transition_id');
        $existingTransitionExpiresAt = (int) Arr::getPath(
            (array) $persisted,
            'transition_expires_at',
            0
        );
        if (Str::isNotEmpty((string) $existingTransition)
            && $this->claimIsLive($existingTransitionExpiresAt)
        ) {
            return AgentRuntimeResult::denied(
                $action,
                $agentId,
                $context->tenantId(),
                $source,
                'agent_transition_in_progress'
            );
        }
        $claimed = $this->states->compareAndPut(
            $agentId,
            $context->tenantId(),
            [
                'state' => $persisted === null ? null : $source,
                'transition_id' => $existingTransition,
                'transition_expires_at' => Arr::getPath(
                    (array) $persisted,
                    'transition_expires_at'
                ),
            ],
            [
                'state' => $source,
                'transition_id' => $transitionId,
                'transition_action' => $action,
                'transition_correlation_id' => $context->correlationId(),
                'transition_expires_at' => $this->leaseExpiresAt(),
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
        try {
            return $this->states->synchronized(
                $this->claimScope('lifecycle', $agentId, $context->tenantId()),
                fn (): AgentRuntimeResult => $this->stableIdempotentSynchronized(
                    $action,
                    $agentId,
                    $context,
                    $state
                )
            );
        } catch (Throwable $exception) {
            if ($this->claimGuardUnavailable($exception)) {
                return AgentRuntimeResult::denied(
                    $action,
                    $agentId,
                    $context->tenantId(),
                    $this->state($agentId, $context),
                    'agent_transition_in_progress'
                );
            }

            return AgentRuntimeResult::failed(
                $action,
                $agentId,
                $context->tenantId(),
                $this->state($agentId, $context),
                $exception->getMessage()
            );
        }
    }

    private function stableIdempotentSynchronized(
        string $action,
        string $agentId,
        AgentRuntimeContext $context,
        string $state
    ): AgentRuntimeResult {
        $persisted = $this->states->get($agentId, $context->tenantId()) ?? [];
        $transitionId = Arr::getPath($persisted, 'transition_id');
        if (Str::isNotEmpty((string) $transitionId)) {
            if ($this->claimIsLive((int) Arr::getPath($persisted, 'transition_expires_at', 0))) {
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

    private function claimScope(string $operation, string $agentId, ?string $tenantId): string
    {
        return Str::make($operation)
            ->append('::')
            ->append($this->key($agentId, $tenantId))
            ->val();
    }

    private function claimGuardUnavailable(Throwable $exception): bool
    {
        return Arr::make([
            'agent_runtime_state_lock_unavailable',
            'agent_runtime_operation_in_progress',
        ])->contains($exception->getMessage());
    }

    private function correlate(AgentRuntimeResult $result, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $result->withMetadata(['correlation_id' => $context->correlationId()]);
    }

    private function operationId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function leaseExpiresAt(): int
    {
        return time() + max(1, $this->claimLeaseSeconds);
    }

    private function claimIsLive(int $expiresAt): bool
    {
        return $expiresAt > time();
    }
}
