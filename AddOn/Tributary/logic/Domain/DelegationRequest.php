<?php

declare(strict_types=1);

namespace AddOn\Tributary\Domain;

/** A service-produced receipt of authority, never accepted as execution authority. */
final readonly class DelegationRequest
{
    public array $targetAgents;
    public array $capabilities;
    public array $input;

    public function __construct(public string $applicationId, public string $tenantId,
        public string $projectId, public string $taskId, public string $actorId,
        public string $correlationId, public string $sourceAgent, array $targetAgents,
        array $capabilities, array $input)
    {
        foreach ([$applicationId, $tenantId, $projectId, $taskId, $actorId, $correlationId, $sourceAgent] as $id) {
            Project::identifier($id);
        }
        Project::identifiers($targetAgents);
        Project::identifiers($capabilities);
        [$this->targetAgents, $this->capabilities, $this->input] = Project::snapshot([$targetAgents, $capabilities, $input]);
    }

    public function toArray(): array
    {
        return ['version' => 1, 'application_id' => $this->applicationId, 'tenant_id' => $this->tenantId,
            'project_id' => $this->projectId, 'task_id' => $this->taskId, 'actor_id' => $this->actorId,
            'correlation_id' => $this->correlationId, 'source_agent' => $this->sourceAgent,
            'target_agents' => $this->targetAgents, 'capabilities' => $this->capabilities, 'input' => $this->input];
    }
}
