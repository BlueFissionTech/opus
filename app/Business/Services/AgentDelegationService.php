<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentDelegationResult;
use App\Domain\Agents\AgentDelegationPattern;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\AgentRuntimeContext;
use App\Domain\Agents\AgentRuntimeResult;
use BlueFission\Arr;
use BlueFission\Automata\LLM\Agent\Orchestration\OrchestrationConfig;
use BlueFission\Automata\LLM\Agent\Orchestration\OrchestrationResult;
use BlueFission\Automata\LLM\Agent\Orchestration\Orchestrator;
use BlueFission\Services\Service;
use BlueFission\Str;
use Throwable;

final class AgentDelegationService extends Service
{
    public function __construct(private AgentCompositionService $agents)
    {
        parent::__construct();
    }

    public function delegate(
        string $coordinatorId,
        array $specialistIds,
        array $task,
        AgentRuntimeContext $context
    ): AgentDelegationResult {
        if (!$this->agents->matchesApplicationScope($context)) {
            return AgentDelegationResult::denied($coordinatorId, [], 'agent_application_scope_mismatch');
        }
        $participants = Arr::make($specialistIds)
            ->map(fn ($id): string => Str::make((string) $id)->trim()->val())
            ->filter(fn (string $id): bool => Str::isNotEmpty($id))
            ->unique()->values();
        $coordinator = $this->agents->descriptorFor($coordinatorId);

        if (!$this->isCoordinator($coordinator)) {
            return AgentDelegationResult::denied(
                $coordinatorId,
                $participants->toArray(),
                'central_agent_required'
            );
        }
        if ($participants->count() === 0) {
            return AgentDelegationResult::denied($coordinatorId, [], 'specialist_agent_required');
        }

        $invalid = $participants->filter(function (string $agentId): bool {
            $descriptor = $this->agents->descriptorFor($agentId);

            return !$this->isSpecialist($descriptor);
        });
        if ($invalid->count() > 0) {
            return AgentDelegationResult::denied(
                $coordinatorId,
                $participants->toArray(),
                'specialist_agent_invalid'
            );
        }

        $evidence = Arr::make([]);
        try {
            $orchestration = $this->orchestrator(
                $coordinatorId,
                $participants->toArray(),
                $task,
                $context,
                $evidence
            )->run([
                'task' => $task,
                'context' => $context->toArray(),
                'coordinator_id' => $coordinatorId,
                'participants' => $participants->toArray(),
            ]);

            return $this->result(
                $coordinatorId,
                $participants->toArray(),
                $orchestration,
                $context,
                $evidence
            );
        } catch (Throwable) {
            return new AgentDelegationResult(
                false,
                AgentDelegationResult::FAILED,
                $coordinatorId,
                $participants->toArray(),
                diagnostics: ['agent_delegation_operation_failed'],
                metadata: [
                    'tenant_id' => $context->tenantId(),
                    'correlation_id' => $context->correlationId(),
                    'application_id' => $context->applicationId(),
                    'execution_evidence' => $evidence->values()->toArray(),
                ]
            );
        }
    }

    private function orchestrator(
        string $coordinatorId,
        array $participants,
        array $task,
        AgentRuntimeContext $context,
        Arr $evidence
    ): Orchestrator {
        $workers = Arr::make([]);
        Arr::make($participants)->each(function (string $agentId) use (
            $workers,
            $coordinatorId,
            $participants,
            $task,
            $context,
            $evidence
        ): void {
            $workers->set($agentId, [
                'handler' => fn (array $_input, array $_priorResults): array => $this->workerResult(
                    $agentId,
                    $this->agents->execute($agentId, [
                        'operation' => 'delegated_task',
                        'input' => $task,
                        'delegation' => [
                            'coordinator_id' => $coordinatorId,
                            'participants' => $participants,
                        ],
                    ], $context),
                    $context,
                    $evidence
                ),
                'metadata' => [
                    'agent_id' => $agentId,
                    'black_box' => true,
                ],
            ]);
        });

        return new Orchestrator(new OrchestrationConfig([
            'pattern' => OrchestrationConfig::HIERARCHICAL,
            'supervisor' => fn (array $_input, array $_priorResults): array => $this->supervisorResult(
                $coordinatorId,
                $participants,
                $task,
                $context,
                $evidence
            ),
            'workers' => $workers->toArray(),
        ]), [new AgentDelegationPattern()]);
    }

    private function supervisorResult(
        string $coordinatorId,
        array $participants,
        array $task,
        AgentRuntimeContext $context,
        Arr $evidence
    ): array {
        $result = $this->agents->execute($coordinatorId, [
            'operation' => 'plan_delegation',
            'input' => $task,
            'available_specialists' => $participants,
        ], $context);
        $selected = Arr::make($participants);
        $normalized = $this->workerResult($coordinatorId, $result, $context, $evidence);
        if ($result->ok() && Arr::is($result->output()) && Arr::hasKey($result->output(), 'workers')) {
            $value = Arr::getPath($result->output(), 'workers');
            $requested = Arr::is($value) ? Arr::toArray($value, true) : null;
            // The installed Arr has no strict list predicate. This is a schema boundary.
            $valid = $requested !== null && array_is_list($requested);
            foreach ($requested ?? [] as $agentId) {
                if (!Str::is($agentId) || !Arr::has($participants, $agentId, true)) {
                    $valid = false;
                    break;
                }
            }
            $selected = Arr::make($valid ? $requested : [])->unique();
            if (!$valid) {
                $normalized['status'] = AgentRuntimeResult::FAILED;
                $normalized['confidence'] = null;
                $normalized['metadata']['diagnostics'][] = 'agent_delegation_selection_invalid';
            }
        }
        if (!$result->ok()) {
            $selected = Arr::make([]);
        }

        $normalized['output'] = [
            'workers' => $selected->toArray(),
            'plan' => $result->output(),
        ];

        return $normalized;
    }

    private function workerResult(
        string $agentId,
        AgentRuntimeResult $result,
        AgentRuntimeContext $context,
        Arr $evidence
    ): array {
        $source = Arr::make($result->toArray());
        $metadata = Arr::make((array) $source->get('metadata'));
        $diagnostics = Arr::make($result->diagnostics());
        $confidence = $metadata->get('confidence');
        $confidence = $this->finiteNumber($confidence) && $confidence >= 0 && $confidence <= 1
            ? (float) $confidence : null;
        // Keep scoped evidence outside Automata's global completion-hook payload.
        $evidence->set($agentId, [
            'agent_id' => $agentId,
            'application_id' => $context->applicationId(),
            'tenant_id' => $context->tenantId(),
            'correlation_id' => $context->correlationId(),
            'execution_id' => $metadata->get('execution_id'),
            'status' => $result->status(),
            'usage' => $this->usageEvidence($metadata->get('usage'), $diagnostics),
            'trace' => $this->traceEvidence((array) $source->get('trace'), $diagnostics),
        ]);
        return [
            'status' => $result->status(),
            'output' => $result->output(),
            'confidence' => $confidence,
            'metadata' => [
                'agent_id' => $agentId,
                'diagnostics' => $diagnostics->unique()->toArray(),
            ],
        ];
    }

    private function usageEvidence(mixed $reported, Arr $diagnostics): ?array
    {
        if ($reported === null) {
            return null;
        }
        if (!Arr::is($reported)) {
            $diagnostics->push('agent_delegation_usage_invalid');
            return null;
        }
        $reported = Arr::make($reported);
        $usage = Arr::make([]);
        foreach (['cost', 'latency_ms', 'energy', 'invocations'] as $field) {
            if (!$reported->hasKey($field)) {
                continue;
            }
            $value = $reported->get($field);
            $integer = Arr::has(['latency_ms', 'invocations'], $field, true);
            if (!$this->finiteNumber($value) || $value < 0 || ($integer && !is_int($value))) {
                $diagnostics->push('agent_delegation_usage_invalid');
                continue;
            }
            $usage->set($field, $value);
        }
        return $usage->count() === 0 ? null : $usage->toArray();
    }

    private function traceEvidence(array $reported, Arr $diagnostics): array
    {
        $trace = Arr::make([]);
        foreach ($reported as $event) {
            $entry = Arr::is($event) ? Arr::make($event) : Arr::make([]);
            $operation = $entry->get('operation');
            $status = $entry->get('status');
            $attempts = $entry->get('attempt_count');
            if (!Str::is($operation)
                || !Str::make($operation)->matches('/^[a-z][a-z0-9_.:-]{0,95}$/i')
                || !Arr::has(['completed', 'denied', 'failed', 'unavailable'], $status, true)
                || ($entry->hasKey('attempt_count') && (!is_int($attempts) || $attempts < 0))
            ) {
                $diagnostics->push('agent_delegation_trace_invalid');
                continue;
            }
            $projected = Arr::make(['operation' => $operation, 'status' => $status]);
            if ($entry->hasKey('attempt_count')) {
                $projected->set('attempt_count', $attempts);
            }
            $trace->push($projected->toArray());
        }
        return $trace->toArray();
    }

    private function finiteNumber(mixed $value): bool
    {
        // The installed Num has no strict native-type/finite predicate.
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private function result(
        string $coordinatorId,
        array $participants,
        OrchestrationResult $orchestration,
        AgentRuntimeContext $context,
        Arr $evidence
    ): AgentDelegationResult {
        $data = Arr::make($orchestration->toArray());
        $represented = Arr::make([]);
        $workerResults = Arr::make((array) $data->get('worker_results'))->map(
            function (array $worker) use ($coordinatorId, $evidence, $represented): array {
                $agentId = $worker['name'] === 'supervisor' ? $coordinatorId : $worker['name'];
                if ($evidence->hasKey($agentId)) {
                    $worker['metadata'] = Arr::merge((array) ($worker['metadata'] ?? []), $evidence->get($agentId));
                    $represented->set($agentId, true);
                }
                return $worker;
            }
        );
        $specialists = $workerResults->filter(
            fn ($result): bool => Arr::getPath((array) $result, 'name') !== 'supervisor'
        );
        $completed = $specialists->filter(
            fn ($result): bool => Arr::getPath((array) $result, 'status') === AgentRuntimeResult::COMPLETED
        )->count();
        $supervisorStatus = (string) Arr::getPath(
            (array) $workerResults->get(0),
            'status',
            AgentRuntimeResult::FAILED
        );

        if ($data->get('status') === AgentDelegationResult::FAILED
            || $supervisorStatus !== AgentRuntimeResult::COMPLETED
        ) {
            $status = AgentDelegationResult::FAILED;
        } elseif ($completed === $specialists->count()) {
            $status = AgentDelegationResult::COMPLETED;
        } elseif ($completed > 0) {
            $status = AgentDelegationResult::PARTIAL;
        } else {
            $status = AgentDelegationResult::FAILED;
        }

        $diagnostics = Arr::make([]);
        $workerResults->each(function ($result) use ($diagnostics): void {
            $diagnostics->merge(
                (array) Arr::getPath((array) $result, 'metadata.diagnostics', [])
            );
        });
        $metadata = Arr::make([
            'pattern' => $data->get('pattern'),
            'confidence' => $data->get('confidence'),
            'application_id' => $context->applicationId(),
            'tenant_id' => $context->tenantId(),
            'correlation_id' => $context->correlationId(),
        ]);
        $unrepresented = $evidence->filter(
            fn (array $receipt, string $agentId): bool => !$represented->hasKey($agentId)
        );
        if ($unrepresented->count() > 0) {
            $metadata->set('execution_evidence', $unrepresented->values()->toArray());
        }

        return new AgentDelegationResult(
            $status === AgentDelegationResult::COMPLETED,
            $status,
            $coordinatorId,
            $participants,
            $data->get('output'),
            $workerResults->toArray(),
            (array) $data->get('conflicts'),
            $diagnostics->toArray(),
            $metadata->toArray()
        );
    }

    private function isCoordinator(?AgentDescriptor $descriptor): bool
    {
        return $descriptor instanceof AgentDescriptor
            && $descriptor->owner() === 'application'
            && $descriptor->mode() === 'central';
    }

    private function isSpecialist(?AgentDescriptor $descriptor): bool
    {
        return $descriptor instanceof AgentDescriptor
            && $descriptor->owner() !== 'application'
            && Arr::make(['specialist', 'generated'])->has($descriptor->mode(), true);
    }
}
