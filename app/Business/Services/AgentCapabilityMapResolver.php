<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentCapabilityMap;
use App\Domain\Agents\AgentDescriptor;
use App\Domain\Agents\ResolvedAgentToolMap;
use BlueFission\Arr;

final class AgentCapabilityMapResolver
{
    private Arr $maps;
    private AgentCapabilityMapValidator $validator;

    public function __construct(
        private AgentCapabilityMap $application,
        array $addOns = [],
        private ?AgentCapabilityMapCatalog $catalog = null,
        ?AgentCapabilityMapValidator $validator = null
    ) {
        $this->maps = Arr::make([]);
        $this->validator = $validator ?? new AgentCapabilityMapValidator();
        Arr::make($addOns)->each(function ($map, $owner): void {
            if ($map instanceof AgentCapabilityMap && $map->owner() === $owner) {
                $this->maps->set((string) $owner, $map);
            }
        });
    }

    public function resolve(
        string $agentId,
        array $activeAddOns = [],
        array $lifecycleStates = [],
        array $capabilities = [],
        ?string $tenantId = null
    ): ResolvedAgentToolMap {
        $activeAddOns = Arr::make($activeAddOns);
        $lifecycleStates = Arr::make($lifecycleStates);
        $capabilities = Arr::make($capabilities);
        $decisions = Arr::make([]);
        $versions = Arr::make([]);
        $this->loadActiveMaps($activeAddOns);
        $relationships = Arr::make($this->validator->validateRelationships(
            $this->application,
            $this->maps->toArray()
        ));
        $relationshipsValid = (bool) $relationships->get('valid');
        $source = $this->find($agentId);

        if ($source === null) {
            return $this->resolved($agentId, $tenantId, [], $versions, $decisions, 'agent_not_found');
        }

        [$map, $agent] = $source;
        if (!$this->isActive($agent, $activeAddOns, $lifecycleStates)) {
            return $this->resolved($agentId, $tenantId, [], $versions, $decisions, 'agent_inactive');
        }
        if ($agent->mode() === 'disabled') {
            return $this->resolved($agentId, $tenantId, [], $versions, $decisions, 'agent_disabled');
        }
        if ($agent->mode() === 'central' && $agent->owner() !== 'application') {
            return $this->resolved($agentId, $tenantId, [], $versions, $decisions, 'agent_delegated_to_central');
        }
        if (!$this->hasPermissions($agent, $capabilities)) {
            return $this->resolved($agentId, $tenantId, [], $versions, $decisions, 'agent_permission_denied');
        }

        $tools = Arr::make($agent->tools());
        $versions->set($map->owner(), $map->version());
        $decisions->push(['decision' => 'allow', 'reason' => 'agent_local_tools']);

        Arr::make($agent->imports())->each(function ($requested, $targetId) use (
            $agent,
            $activeAddOns,
            $lifecycleStates,
            $capabilities,
            $tools,
            $versions,
            $decisions
        ): void {
            $target = $this->find((string) $targetId);
            if ($target === null) {
                $decisions->push(['decision' => 'deny', 'reason' => 'import_agent_missing', 'agent' => $targetId]);
                return;
            }
            [$targetMap, $targetAgent] = $target;
            if (!$this->isActive($targetAgent, $activeAddOns, $lifecycleStates)) {
                $decisions->push(['decision' => 'deny', 'reason' => 'import_agent_inactive', 'agent' => $targetId]);
                return;
            }
            if (!$this->hasPermissions($targetAgent, $capabilities)) {
                $decisions->push(['decision' => 'deny', 'reason' => 'import_permission_denied', 'agent' => $targetId]);
                return;
            }
            if ($agent->id() === 'opus.central'
                && $targetAgent->mode() !== 'central'
            ) {
                $decisions->push(['decision' => 'deny', 'reason' => 'specialist_requires_delegation', 'agent' => $targetId]);
                return;
            }

            $reciprocal = Arr::make($targetAgent->exports())->get($agent->id());
            if (!Arr::is($reciprocal)) {
                $decisions->push(['decision' => 'deny', 'reason' => 'reciprocal_grant_missing', 'agent' => $targetId]);
                return;
            }

            $granted = Arr::make((array) $requested)
                ->filter(fn (string $tool): bool => Arr::make($reciprocal)->has($tool, true))
                ->filter(fn (string $tool): bool => Arr::make($targetAgent->tools())->has($tool, true));
            $tools->merge($granted->toArray());
            $versions->set($targetMap->owner(), $targetMap->version());
            $decisions->push([
                'decision' => $granted->count() === 0 ? 'deny' : 'allow',
                'reason' => $granted->count() === 0 ? 'reciprocal_tool_missing' : 'reciprocal_grant',
                'agent' => $targetId,
            ]);
        });

        if (!$relationshipsValid) {
            $decisions->push(['decision' => 'deny', 'reason' => 'agent_relationship_invalid']);
        }

        return new ResolvedAgentToolMap(
            $agentId,
            $tenantId,
            $tools->unique()->toArray(),
            $versions->toArray(),
            $decisions->toArray()
        );
    }

    private function find(string $agentId): ?array
    {
        $agent = $this->application->agent($agentId);
        if ($agent instanceof AgentDescriptor) {
            return [$this->application, $agent];
        }

        foreach ($this->maps as $map) {
            $agent = $map->agent($agentId);
            if ($agent instanceof AgentDescriptor) {
                return [$map, $agent];
            }
        }

        return null;
    }

    private function loadActiveMaps(Arr $activeAddOns): void
    {
        if ($this->catalog === null) {
            return;
        }

        Arr::make($this->catalog->load($activeAddOns->toArray()))
            ->each(function ($map, $owner): void {
                if ($map instanceof AgentCapabilityMap && $map->owner() === $owner) {
                    $this->maps->set((string) $owner, $map);
                }
            });
    }

    private function isActive(AgentDescriptor $agent, Arr $activeAddOns, Arr $lifecycleStates): bool
    {
        if ($agent->owner() === 'application') {
            return true;
        }
        if (!$activeAddOns->has($agent->owner(), true)) {
            return false;
        }

        $state = (string) $lifecycleStates->get($agent->owner());

        return Arr::make($agent->lifecycleStates())->has($state, true);
    }

    private function hasPermissions(AgentDescriptor $agent, Arr $capabilities): bool
    {
        return Arr::make($agent->permissions())
            ->filter(fn (string $permission): bool => !$capabilities->has($permission, true))
            ->count() === 0;
    }

    private function resolved(
        string $agentId,
        ?string $tenantId,
        array $tools,
        Arr $versions,
        Arr $decisions,
        string $reason
    ): ResolvedAgentToolMap {
        $decisions->push(['decision' => 'deny', 'reason' => $reason]);

        return new ResolvedAgentToolMap(
            $agentId,
            $tenantId,
            $tools,
            $versions->toArray(),
            $decisions->toArray()
        );
    }
}
