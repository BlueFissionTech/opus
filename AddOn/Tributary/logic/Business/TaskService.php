<?php

declare(strict_types=1);

namespace AddOn\Tributary\Business;

use AddOn\Tributary\Domain\DelegationRequest;
use AddOn\Tributary\Domain\Project;
use App\Business\Services\AgentCompositionService;
use App\Business\Services\AgentDelegationService;
use App\Domain\Agents\AgentApplicationScope;
use App\Domain\Agents\AgentRuntimeContext;
use BlueFission\Arr;
use BlueFission\DevElation;
use BlueFission\Services\Service;
use BlueFission\Str;
use InvalidArgumentException;
use Throwable;

final class TaskService extends Service
{
    private array $projects = [];
    private static bool $executing = false;
    private AgentDelegationService $delegation;

    public function __construct(private AgentCompositionService $agents, array $projects)
    {
        parent::__construct();
        $this->delegation = new AgentDelegationService($agents);
        foreach ($projects as $project) {
            if (!$project instanceof Project) { throw new InvalidArgumentException('Expected a project record.'); }
            $key = $this->key($project->applicationId, $project->tenantId, $project->id);
            if (Arr::hasKey($this->projects, $key)) { throw new InvalidArgumentException('Duplicate project scope.'); }
            $this->projects[$key] = $project;
        }
    }

    public function discover(string $projectId, AgentRuntimeContext $context): array
    {
        $project = $this->authorized($projectId, $context, 'tributary.read');
        if ($project === null) { return $this->denied(); }
        return ['ok' => true, 'status' => 'completed', 'output' => [
            'project_id' => $project->id, 'profiles' => Arr::make($project->profiles)->map(
                fn (array $profile): array => ['agent_id' => $profile['agent_id'], 'profile' => $profile['profile']]
            )->toArray(),
            'tasks' => Arr::make($project->tasks)->keys()->toArray(),
        ], 'diagnostics' => []];
    }

    public function run(string $projectId, string $taskId, AgentRuntimeContext $context): array
    {
        if (self::$executing) { return $this->denied(); }
        $project = $this->authorized($projectId, $context, 'tributary.delegate');
        $task = $project === null ? null : ($project->tasks[$taskId] ?? null);
        if (!Arr::is($task) || $context->correlationId() === null) { return $this->denied(); }
        $grants = $this->grants($project, $context);
        foreach ($task['capabilities'] as $grant) {
            if (!Arr::has($grants, $grant, true)) { return $this->denied(); }
        }
        foreach (Arr::make($task['targets'])->push($task['source'])->toArray() as $profileId) {
            $profile = $project->profiles[$profileId];
            if ($this->agents->descriptorFor($profile['agent_id'])?->profile() !== $profile['profile']) {
                return $this->denied();
            }
        }
        self::$executing = true;
        $started = false;
        try {
            $request = new DelegationRequest($project->applicationId, $project->tenantId, $project->id,
                $taskId, Arr::getPath($context->actor(), 'id'), $context->correlationId(),
                $project->profiles[$task['source']]['agent_id'],
                Arr::make($task['targets'])->map(fn (string $id): string => $project->profiles[$id]['agent_id'])->unique()->toArray(),
                $task['capabilities'], $task['input']);
            // A filter can customize task data, never the authority receipt or grants.
            $input = DevElation::apply('tributary.task.input', $request->input);
            if (!is_array($input)) { throw new InvalidArgumentException('Invalid filtered task input.'); }
            Project::data($input);
            $bounded = new AgentRuntimeContext($context->tenantId(), ['id' => $request->actorId],
                $context->activeAddOns(), $context->lifecycleStates(), $request->capabilities,
                $request->correlationId, new AgentApplicationScope($request->applicationId));
            $receipt = $request->toArray();
            unset($receipt['input']);
            $started = true;
            $result = $this->delegation->delegate($request->sourceAgent, $request->targetAgents,
                ['assignment' => $receipt, 'input' => $input], $bounded)->toArray();
            $result['assignment'] = $receipt;
            try { DevElation::do('tributary.task.result', [['version' => 1, 'status' => $result['status']]]); }
            catch (Throwable) { /* Observation cannot change the execution result or cause replay. */ }
            return $result;
        } catch (Throwable) {
            return ['ok' => false, 'status' => 'failed', 'output' => null,
                'diagnostics' => ['tributary_task_failed'], 'operation_started' => $started,
                'effects' => $started ? 'unknown' : 'none'];
        } finally { self::$executing = false; }
    }

    private function authorized(string $id, AgentRuntimeContext $context, string $operation): ?Project
    {
        $actor = Arr::getPath($context->actor(), 'id');
        if (!Str::is($actor) || Str::isEmpty($actor) || $context->applicationId() === null || $context->tenantId() === null) {
            return null;
        }
        $project = $this->projects[$this->key($context->applicationId(), $context->tenantId(), $id)] ?? null;
        if (!$project instanceof Project || !Arr::has($this->grants($project, $context), $operation, true)) { return null; }
        return $project;
    }

    private function grants(Project $project, AgentRuntimeContext $context): array
    {
        $roles = $project->members[Arr::getPath($context->actor(), 'id')] ?? [];
        $grants = Arr::make([]);
        foreach ($roles as $role) { $grants->merge($project->roles[$role]); }
        return $grants->filter(fn (string $grant): bool => Arr::has($context->capabilities(), $grant, true))->unique()->toArray();
    }

    private function key(string $application, string $tenant, string $project): string
    {
        return Arr::make([$application, $tenant, $project])->toJson();
    }

    private function denied(): array
    {
        return ['ok' => false, 'status' => 'denied', 'output' => null, 'diagnostics' => ['tributary_access_denied']];
    }
}
