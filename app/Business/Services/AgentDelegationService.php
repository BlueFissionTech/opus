<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentDelegationResult;
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
        $participants = Arr::make($specialistIds)
            ->map(fn ($id): string => Str::make((string) $id)->trim()->val())
            ->filter(fn (string $id): bool => Str::isNotEmpty($id))
            ->unique();
        $coordinator = $this->agents->descriptorFor($coordinatorId);

        if (!$this->isCoordinator($coordinator)) {
            return AgentDelegationResult::denied(
                $coordinatorId,
                $participants->toArray(),
                'central_agent_required'
            );
        }
        if ($participants->isEmpty()) {
            return AgentDelegationResult::denied($coordinatorId, [], 'specialist_agent_required');
        }

        $invalid = $participants->filter(function (string $agentId): bool {
            $descriptor = $this->agents->descriptorFor($agentId);

            return !$this->isSpecialist($descriptor);
        });
        if ($invalid->isNotEmpty()) {
            return AgentDelegationResult::denied(
                $coordinatorId,
                $participants->toArray(),
                'specialist_agent_invalid'
            );
        }

        try {
            $orchestration = $this->orchestrator(
                $coordinatorId,
                $participants->toArray(),
                $task,
                $context
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
                $context
            );
        } catch (Throwable $exception) {
            return AgentDelegationResult::failed(
                $coordinatorId,
                $participants->toArray(),
                $exception->getMessage()
            );
        }
    }

    private function orchestrator(
        string $coordinatorId,
        array $participants,
        array $task,
        AgentRuntimeContext $context
    ): Orchestrator {
        $workers = Arr::make([]);
        Arr::make($participants)->each(function (string $agentId) use (
            $workers,
            $coordinatorId,
            $participants,
            $task,
            $context
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
                    ], $context)
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
                $context
            ),
            'workers' => $workers->toArray(),
        ]));
    }

    private function supervisorResult(
        string $coordinatorId,
        array $participants,
        array $task,
        AgentRuntimeContext $context
    ): array {
        $result = $this->agents->execute($coordinatorId, [
            'operation' => 'plan_delegation',
            'input' => $task,
            'available_specialists' => $participants,
        ], $context);
        $selected = Arr::make($participants);
        if (Arr::is($result->output())) {
            $requested = Arr::make((array) Arr::getPath((array) $result->output(), 'workers'));
            if ($requested->isNotEmpty()) {
                $selected = $requested
                    ->filter(fn ($agentId): bool => Arr::make($participants)->has((string) $agentId, true))
                    ->unique();
            }
        }
        if (!$result->ok()) {
            $selected = Arr::make([]);
        }

        $normalized = $this->workerResult($coordinatorId, $result);
        $normalized['output'] = [
            'workers' => $selected->toArray(),
            'plan' => $result->output(),
        ];

        return $normalized;
    }

    private function workerResult(string $agentId, AgentRuntimeResult $result): array
    {
        return [
            'status' => $result->status(),
            'output' => $result->output(),
            'confidence' => $result->ok() ? 1.0 : 0.0,
            'metadata' => [
                'agent_id' => $agentId,
                'diagnostics' => $result->diagnostics(),
            ],
        ];
    }

    private function result(
        string $coordinatorId,
        array $participants,
        OrchestrationResult $orchestration,
        AgentRuntimeContext $context
    ): AgentDelegationResult {
        $data = Arr::make($orchestration->toArray());
        $workerResults = Arr::make((array) $data->get('worker_results'));
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

        return new AgentDelegationResult(
            $status === AgentDelegationResult::COMPLETED,
            $status,
            $coordinatorId,
            $participants,
            $data->get('output'),
            $workerResults->toArray(),
            (array) $data->get('conflicts'),
            $diagnostics->toArray(),
            [
                'pattern' => $data->get('pattern'),
                'confidence' => $data->get('confidence'),
                'tenant_id' => $context->tenantId(),
                'correlation_id' => $context->correlationId(),
            ]
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
