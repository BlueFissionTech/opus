<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentApplicationScope;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use App\Domain\Agents\IAgentRuntime;
use App\Domain\Agents\IRetirableAgentRuntime;
use App\Domain\Agents\IAgentRuntimeFactory;
use App\Domain\Agents\IAgentRuntimeStateStore;
use App\Domain\Agents\IApplicationScopedAgentRuntimeStateStore;
use BlueFission\Arr;
use BlueFission\DevElation;
use BlueFission\Services\Service;
use BlueFission\Str;
use Throwable;

final class AgentCompositionService extends Service
{
    private const CLAIM_LEASE_SECONDS = 300;
    private static bool $observingResult = false;

    public const REGISTERED = 'registered';
    public const UNREGISTERED = 'unregistered';
    public const RUNNING = 'running';
    public const SUSPENDED = 'suspended';
    public const STOPPED = 'stopped';
    public const FAILED = 'failed';
    public const UNKNOWN = 'unknown';

    private Arr $descriptors;
    private Arr $runtimes;
    private Arr $runtimeRegistrations;
    private Arr $retiring;
    private ?AgentApplicationScope $applicationScope;

    public function __construct(
        private AgentCapabilityMapResolver $resolver,
        private IAgentRuntimeFactory $factory,
        private IAgentRuntimeStateStore $states,
        private int $claimLeaseSeconds = self::CLAIM_LEASE_SECONDS
    ) {
        parent::__construct();
        $this->descriptors = Arr::make([]);
        $this->runtimes = Arr::make([]);
        $this->runtimeRegistrations = Arr::make([]);
        $this->retiring = Arr::make([]);
        $this->applicationScope = $states instanceof IApplicationScopedAgentRuntimeStateStore
            ? $states->applicationScope()
            : null;
    }

    public function registerMap(AgentCapabilityMap $map): AgentRuntimeResult
    {
        if ($this->retiring->isNotEmpty()) {
            return AgentRuntimeResult::denied(
                'register', $map->owner(), null, self::UNKNOWN, 'agent_runtime_retirement_in_progress'
            );
        }
        if (self::$observingResult) {
            return AgentRuntimeResult::denied(
                'register', $map->owner(), null, self::UNKNOWN, 'agent_result_observer_reentry_denied'
            );
        }
        foreach ($map->agents() as $descriptor) {
            if (!$descriptor instanceof AgentDescriptor) {
                continue;
            }
            $existing = $this->descriptors->get($descriptor->id());
            if ($existing instanceof AgentDescriptor
                && $this->descriptorContract($existing) !== $this->descriptorContract($descriptor)
                && $this->hasCachedRuntime($descriptor->id())
            ) {
                return AgentRuntimeResult::denied(
                    'register', $map->owner(), null, self::UNKNOWN, 'agent_runtime_unregistration_required'
                );
            }
        }
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

    private function hasCachedRuntime(string $agentId): bool
    {
        $suffix = '::' . $agentId;
        return $this->runtimes->keys()
            ->filter(fn ($key): bool => Str::make((string) $key)->endsWith($suffix))
            ->count() > 0;
    }

    public function inspect(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $authorized = $this->authorize('inspect', $agentId, $context);
        if ($authorized !== null) {
            return $this->correlate($authorized, $context);
        }

        $reason = $this->runtimeUnavailableReason($agentId, $context);
        $available = $reason === null;

        return $this->correlate(new AgentRuntimeResult(
            $available,
            $available ? AgentRuntimeResult::COMPLETED : AgentRuntimeResult::UNAVAILABLE,
            'inspect',
            $agentId,
            $context->tenantId(),
            $this->state($agentId, $context),
            ['available' => $available],
            $reason === null ? [] : [$reason]
        ), $context);
    }

    public function start(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $authorized = $this->authorize('start', $agentId, $context);
        if ($authorized !== null) {
            return $this->correlate($authorized, $context);
        }

        $current = $this->state($agentId, $context);
        if ($current === self::UNREGISTERED) {
            return $this->correlate(AgentRuntimeResult::denied(
                'start', $agentId, $context->tenantId(), $current, 'agent_registration_required'
            ), $context);
        }
        if ($current === self::RUNNING) {
            return $this->correlate($this->stableIdempotent('start', $agentId, $context, self::RUNNING), $context);
        }
        if ($current === self::SUSPENDED) {
            return $this->correlate(
                AgentRuntimeResult::denied('start', $agentId, $context->tenantId(), $current, 'agent_resume_required'),
                $context
            );
        }

        return $this->correlate($this->invokeLifecycle('start', $agentId, $context, $current, self::RUNNING), $context);
    }

    public function suspend(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->correlate(
            $this->transition('suspend', $agentId, $context, [self::RUNNING], self::SUSPENDED), $context
        );
    }

    public function resume(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->correlate(
            $this->transition('resume', $agentId, $context, [self::SUSPENDED], self::RUNNING), $context
        );
    }

    public function stop(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->correlate($this->transition(
            'stop',
            $agentId,
            $context,
            [self::RUNNING, self::SUSPENDED, self::FAILED],
            self::STOPPED
        ), $context);
    }

    public function registerRuntime(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $authorized = $this->authorize('registerRuntime', $agentId, $context);
        if ($authorized !== null) {
            return $this->correlate($authorized, $context);
        }
        if ($this->states->get($agentId, $context->tenantId()) === null) {
            return $this->correlate($this->invokeLifecycle(
                'registerRuntime', $agentId, $context, self::REGISTERED, self::REGISTERED
            ), $context);
        }
        return $this->correlate($this->transition(
            'registerRuntime', $agentId, $context, [self::UNREGISTERED], self::REGISTERED
        ), $context);
    }

    public function unregister(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        return $this->correlate($this->transition(
            'unregister', $agentId, $context,
            [self::REGISTERED, self::RUNNING, self::SUSPENDED, self::STOPPED, self::FAILED],
            self::UNREGISTERED
        ), $context);
    }

    public function retire(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $authorized = $this->authorize('retire', $agentId, $context, allowStaleRetirement: true);
        if ($authorized !== null) {
            return $this->correlate($authorized, $context);
        }
        $key = $this->key($agentId, $context->tenantId());
        $runtime = $this->runtimes->get($key);
        $state = $this->state($agentId, $context);
        if (!$runtime instanceof IAgentRuntime) {
            return $this->correlate(AgentRuntimeResult::completed(
                'retire', $agentId, $context->tenantId(), $state,
                metadata: ['local_runtime_present' => false]
            ), $context);
        }
        if (!$this->cachedRegistrationStale($agentId, $context)) {
            return $this->correlate(AgentRuntimeResult::denied(
                'retire', $agentId, $context->tenantId(), $state, 'agent_runtime_not_stale'
            ), $context);
        }
        if (!$runtime instanceof IRetirableAgentRuntime) {
            return $this->correlate(AgentRuntimeResult::unavailable(
                'retire', $agentId, $context->tenantId(), $state, 'agent_runtime_retirement_unsupported'
            ), $context);
        }

        $this->retiring->set($key, true);
        try {
            $cleanup = $runtime->retire($context);
            $state = $this->state($agentId, $context);
            if ($cleanup->ok() && $cleanup->status() === AgentRuntimeResult::COMPLETED) {
                $this->runtimes->delete($key);
                $this->runtimeRegistrations->delete($key);
                $result = AgentRuntimeResult::completed('retire', $agentId, $context->tenantId(), $state,
                    metadata: ['local_runtime_present' => true]);
            } else {
                $result = AgentRuntimeResult::failed('retire', $agentId, $context->tenantId(), $state,
                    'agent_runtime_retirement_incomplete');
            }
        } catch (Throwable) {
            $result = AgentRuntimeResult::failed('retire', $agentId, $context->tenantId(), self::UNKNOWN,
                'agent_runtime_retirement_uncertain');
        } finally {
            $this->retiring->delete($key);
        }
        return $this->correlate($result, $context);
    }

    public function cancel(string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $authorized = $this->authorize('cancel', $agentId, $context);
        if ($authorized !== null) {
            return $this->correlate($authorized, $context);
        }

        try {
            return $this->correlate($this->states->synchronized(
                $this->claimScope('cancellation', $agentId, $context->tenantId()),
                fn (): AgentRuntimeResult => $this->cancelSynchronized($agentId, $context)
            ), $context);
        } catch (Throwable $exception) {
            if ($this->claimGuardUnavailable($exception)) {
                return $this->correlate(AgentRuntimeResult::denied(
                    'cancel',
                    $agentId,
                    $context->tenantId(),
                    $this->state($agentId, $context),
                    'agent_cancellation_in_progress'
                ), $context);
            }

            return $this->correlate(AgentRuntimeResult::failed(
                'cancel',
                $agentId,
                $context->tenantId(),
                $this->state($agentId, $context),
                'agent_runtime_operation_failed'
            ), $context);
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
                $this->states->compareAndPut(
                    $agentId,
                    $context->tenantId(),
                    $completionExpected,
                    $completion
                );
                if (Str::isNotEmpty((string) $coveredExecution)) {
                    $this->states->compareAndPut(
                        $agentId,
                        $context->tenantId(),
                        ['active_execution_id' => $coveredExecution],
                        ['active_execution_id' => null]
                    );
                }

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
                'agent_runtime_operation_failed'
            );
        }
    }

    public function execute(string $agentId, array $task, AgentRuntimeContext $context): AgentRuntimeResult
    {
        $executionId = null;
        $phase = 'preparing';
        try {
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

            $unavailable = $this->runtimeUnavailableReason($agentId, $context);
            if ($unavailable !== null) {
                return $this->correlate(
                    AgentRuntimeResult::unavailable(
                        'execute',
                        $agentId,
                        $context->tenantId(),
                        $current,
                        $unavailable
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
                'registration_id' => Arr::getPath($persisted, 'registration_id'),
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
            $phase = 'executing';
            $result = $runtime->execute($executionId, $task, $context);
            $phase = 'recording';
            $result = $result->withMetadata([
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

            return $this->correlate(
                $result->forScope('execute', $agentId, $context->tenantId(), $latestState), $context
            );
        } catch (Throwable) {
            $claimRelease = 'not_attempted';
            // A reporting failure must not trigger another completion write.
            if ($phase === 'executing' && $executionId !== null) {
                try {
                    $released = $this->states->compareAndPut($agentId, $context->tenantId(), [
                        'active_execution_id' => $executionId,
                    ], [
                        'active_execution_id' => null,
                    ]);
                    $claimRelease = $released ? 'released' : 'not_owned';
                } catch (Throwable) {
                    $claimRelease = 'unknown';
                }
            }
            $metadata = [
                'failure_stage' => $phase,
                'execution_started' => $phase !== 'preparing',
                'effects' => 'unknown',
                'claim_release' => $claimRelease,
                'recovery_required' => $executionId !== null,
            ];
            if ($executionId !== null) {
                $metadata['execution_id'] = $executionId;
            }

            return $this->correlate(
                AgentRuntimeResult::failed(
                    'execute',
                    $agentId,
                    $context->tenantId(),
                    self::UNKNOWN,
                    $phase === 'recording'
                        ? 'agent_runtime_completion_unrecorded'
                        : 'agent_runtime_operation_failed'
                )->withMetadata($metadata),
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
                'agent_runtime_operation_failed'
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
        $activeExecutionId = Arr::getPath((array) $persisted, 'active_execution_id');
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
        $metadataOnly = $action === 'registerRuntime'
            || ($action === 'unregister'
                && Arr::make([self::REGISTERED, self::STOPPED])->contains($source)
                && !Str::isNotEmpty((string) $activeExecutionId));
        $result = ($metadataOnly
            ? AgentRuntimeResult::completed($action, $agentId, $context->tenantId(), $target)
            : $this->invoke($action === 'unregister' ? 'stop' : $action, $agentId, $context))->forScope(
            $action,
            $agentId,
            $context->tenantId(),
            $target
        );
        $state = $result->ok()
            ? $target
            : ($result->status() === AgentRuntimeResult::UNAVAILABLE ? $source : self::FAILED);
        $completion = [
            'state' => $state,
            'status' => $result->status(),
            'diagnostics' => $result->diagnostics(),
            'correlation_id' => $context->correlationId(),
            'transition_id' => null,
            'transition_action' => null,
            'transition_correlation_id' => null,
            'transition_expires_at' => null,
        ];
        if ($result->ok() && Arr::make(['registerRuntime', 'unregister'])->contains($action)) {
            $completion['registration_id'] = $transitionId;
        }
        $updated = $this->states->compareAndPut(
            $agentId,
            $context->tenantId(),
            ['transition_id' => $transitionId],
            $completion
        );
        if (!$updated) {
            return $result
                ->withMetadata(['transition_superseded' => true])
                ->forScope($action, $agentId, $context->tenantId(), $this->state($agentId, $context));
        }
        if (Arr::make(['stop', 'unregister'])->contains($action)
            && $result->ok()
            && Str::isNotEmpty((string) $activeExecutionId)
        ) {
            $this->states->compareAndPut(
                $agentId,
                $context->tenantId(),
                ['active_execution_id' => $activeExecutionId],
                ['active_execution_id' => null]
            );
        }

        if ($action === 'unregister' && $result->ok()) {
            $this->runtimes->delete($this->key($agentId, $context->tenantId()));
            $this->runtimeRegistrations->delete($this->key($agentId, $context->tenantId()));
        }

        return $result->forScope($action, $agentId, $context->tenantId(), $state);
    }

    private function invokeCancellation(
        string $agentId,
        ?string $executionId,
        AgentRuntimeContext $context
    ): AgentRuntimeResult {
        $unavailable = $this->runtimeUnavailableReason($agentId, $context, true);
        if ($unavailable !== null) {
            return AgentRuntimeResult::unavailable(
                'cancel',
                $agentId,
                $context->tenantId(),
                $this->state($agentId, $context),
                $unavailable
            );
        }

        return $this->runtime($agentId, $context)->cancel($executionId, $context);
    }

    private function invoke(string $action, string $agentId, AgentRuntimeContext $context): AgentRuntimeResult
    {
        try {
            $unavailable = $this->runtimeUnavailableReason(
                $agentId,
                $context,
                Arr::make(['suspend', 'stop'])->contains($action)
            );
            if ($unavailable !== null) {
                return AgentRuntimeResult::unavailable(
                    $action,
                    $agentId,
                    $context->tenantId(),
                    $this->state($agentId, $context),
                    $unavailable
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
                'agent_runtime_operation_failed'
            );
        }
    }

    private function runtime(string $agentId, AgentRuntimeContext $context): IAgentRuntime
    {
        $key = $this->key($agentId, $context->tenantId());
        if ($this->cachedRegistrationStale($agentId, $context)) {
            throw new \RuntimeException('agent_runtime_registration_stale');
        }
        $runtime = $this->runtimes->get($key);
        if ($runtime instanceof IAgentRuntime) {
            return $runtime;
        }

        $descriptor = $this->descriptorFor($agentId);
        if (!$descriptor instanceof AgentDescriptor) {
            throw new \RuntimeException('agent_runtime_unavailable');
        }

        $registration = Arr::getPath($this->states->get($agentId, $context->tenantId()) ?? [], 'registration_id');
        $runtime = $this->factory->create($descriptor, $context);
        $this->runtimes->set($key, $runtime);
        $this->runtimeRegistrations->set($key, $registration);

        return $runtime;
    }

    private function runtimeUnavailableReason(
        string $agentId,
        AgentRuntimeContext $context,
        bool $cleanup = false
    ): ?string {
        if ($this->cachedRegistrationStale($agentId, $context)) {
            return 'agent_runtime_registration_stale';
        }
        if ($cleanup && $this->runtimes->get($this->key($agentId, $context->tenantId())) instanceof IAgentRuntime) {
            return null;
        }

        $descriptor = $this->descriptorFor($agentId);
        if (!$descriptor instanceof AgentDescriptor) {
            return 'agent_runtime_unavailable';
        }

        try {
            return $this->factory->available($descriptor, $context)
                ? null
                : 'agent_runtime_unavailable';
        } catch (Throwable) {
            return 'agent_runtime_probe_failed';
        }
    }

    public function matchesApplicationScope(AgentRuntimeContext $context): bool
    {
        return $this->applicationScope?->id() === $context->applicationId();
    }

    private function cachedRegistrationStale(string $agentId, AgentRuntimeContext $context): bool
    {
        $key = $this->key($agentId, $context->tenantId());
        if (!$this->runtimes->get($key) instanceof IAgentRuntime) {
            return false;
        }
        $current = Arr::getPath($this->states->get($agentId, $context->tenantId()) ?? [], 'registration_id');
        return $this->runtimeRegistrations->get($key) !== $current;
    }

    private function authorize(
        string $action,
        string $agentId,
        AgentRuntimeContext $context,
        bool $allowStaleRetirement = false
    ): ?AgentRuntimeResult {
        if (self::$observingResult) {
            return AgentRuntimeResult::denied(
                $action, $agentId, $context->tenantId(), self::UNKNOWN, 'agent_result_observer_reentry_denied'
            );
        }
        if (!$this->matchesApplicationScope($context)) {
            return AgentRuntimeResult::denied(
                $action, $agentId, $context->tenantId(), self::UNKNOWN, 'agent_application_scope_mismatch'
            );
        }
        if ($this->retiring->get($this->key($agentId, $context->tenantId())) === true) {
            return AgentRuntimeResult::denied(
                $action, $agentId, $context->tenantId(), self::UNKNOWN, 'agent_runtime_retirement_in_progress'
            );
        }
        $descriptor = $this->descriptorFor($agentId);
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

        if (!$allowStaleRetirement && $this->cachedRegistrationStale($agentId, $context)) {
            return AgentRuntimeResult::denied(
                $action, $agentId, $context->tenantId(), $state, 'agent_runtime_registration_stale'
            );
        }

        return null;
    }

    public function descriptorFor(string $agentId): ?AgentDescriptor
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
                'agent_runtime_operation_failed'
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
        if ($this->applicationScope !== null && $this->applicationScope->id() === $context->applicationId()) {
            $result = $result->withMetadata(['application_id' => $this->applicationScope->id()]);
        }
        $result = $result->withMetadata(['correlation_id' => $context->correlationId()]);
        if (self::$observingResult) {
            return $result;
        }
        self::$observingResult = true;
        try {
            $status = $result->status();
            $knownStatus = Arr::has(['completed', 'denied', 'failed', 'unavailable'], $status, true);
            $operation = $result->action();
            $summary = [
                'version' => 1,
                'operation' => Arr::has([
                    'inspect', 'start', 'suspend', 'resume', 'stop', 'cancel',
                    'execute', 'registerRuntime', 'unregister', 'retire',
                ], $operation, true) ? $operation : 'unknown',
                'status' => $knownStatus ? $status : 'unknown',
                'ok' => $status === AgentRuntimeResult::COMPLETED && $result->ok(),
            ];
            DevElation::do(ExtensionPointCatalog::AGENT_RUNTIME_RESULT, [$summary]);
        } catch (Throwable) {
            // Observation cannot change a returned outcome or replay its effects.
        } finally {
            self::$observingResult = false;
        }
        return $result;
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
