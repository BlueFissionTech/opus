<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\WiseProfile;
use BlueFission\Arr;
use BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery;
use BlueFission\DevElation;
use BlueFission\Str;
use Closure;
use Throwable;

final class AgentCommandContextProvider
{
    private const CENTRAL_AGENT = 'opus.central';
    private ?Closure $queryResolver;

    public function __construct(
        private ?IActivatedAddOnsQuery $activatedAddOns = null,
        ?callable $queryResolver = null
    ) {
        $this->queryResolver = $queryResolver === null ? null : Closure::fromCallable($queryResolver);
    }

    public function forActor(string $actorId, ?string $tenantId = null): array
    {
        $active = Arr::make([]);
        $states = Arr::make([]);

        try {
            $records = $this->activatedAddOns()?->fetch() ?? [];
            Arr::make(Arr::is($records) ? $records : [])->each(
                function ($record) use ($active, $states): void {
                    $record = Arr::make((array) $record);
                    $name = Str::make((string) $record->get('name'))->trim()->lower()->val();
                    if (Str::isEmpty($name)) {
                        return;
                    }
                    $active->push($name);
                    $states->set($name, 'active');
                }
            );
        } catch (Throwable) {
            // Add-on authority fails closed when lifecycle state cannot be read.
        }

        $context = Arr::make([
            'agent_id' => self::CENTRAL_AGENT,
            'active_addons' => $active->unique()->toArray(),
            'addon_states' => $states->toArray(),
            'capabilities' => [],
        ]);
        if (Str::isNotEmpty($actorId)) {
            $context->set('actor', ['id' => $actorId]);
            $context->set('wise_profile', [
                'type' => WiseProfile::USER,
                'principal_id' => $actorId,
                'tenant_id' => Str::isNotEmpty((string) $tenantId) ? $tenantId : null,
                'roles' => ['user'],
            ]);
        }
        if (Str::isNotEmpty((string) $tenantId)) {
            $context->set('tenant_id', $tenantId);
        }

        $filtered = DevElation::apply('opus.agent.command_context', $context->toArray());
        if (!Arr::is($filtered)) {
            return $context->toArray();
        }
        $filtered = Arr::make($filtered);
        $filteredActor = $filtered->get('actor');
        $filteredActorId = Str::is($filteredActor)
            ? Str::make((string) $filteredActor)->trim()->val()
            : Str::make((string) Arr::getPath((array) $filteredActor, 'id', ''))->trim()->val();
        if (Str::isNotEmpty($actorId) && $filteredActorId !== $actorId) {
            return $context->toArray();
        }
        if (Str::isNotEmpty((string) $tenantId)
            && $filtered->get('tenant_id') !== $tenantId
        ) {
            return $context->toArray();
        }

        $profile = Arr::make((array) $filtered->get('wise_profile'));
        if (Str::isNotEmpty($actorId)
            && ($profile->get('type') !== WiseProfile::USER
                || $profile->get('principal_id') !== $actorId
                || $profile->get('tenant_id') !== (Str::isNotEmpty((string) $tenantId) ? $tenantId : null))
        ) {
            return $context->toArray();
        }

        return $filtered->toArray();
    }

    public function forContinuation(array $priorContext): array
    {
        $context = Arr::make($priorContext);
        $actor = $context->get('actor');
        $actorId = Str::is($actor)
            ? Str::make((string) $actor)->trim()->val()
            : Str::make((string) Arr::getPath((array) $actor, 'id', ''))->trim()->val();
        $tenantId = $context->get('tenant_id');
        $refreshed = Arr::make($this->forActor($actorId, Str::is($tenantId) ? $tenantId : null));

        $agentId = $context->get('agent_id');
        if (Str::is($agentId)
            && Str::make((string) $agentId)->matches('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/')
        ) {
            $refreshed->set('agent_id', $agentId);
        }
        foreach (['wise_profile', 'wise_profile_target'] as $profileKey) {
            $profile = Arr::make((array) $context->get($profileKey));
            $profileTenantId = $profile->get('tenant_id');
            if ($profile->isEmpty()
                || (!Str::isNull($tenantId) && $profileTenantId !== $tenantId)
                || (Str::isNull($tenantId) && !Str::isNull($profileTenantId))
            ) {
                continue;
            }

            $roles = [];
            if ($profileKey === 'wise_profile') {
                $authoritative = Arr::make((array) $refreshed->get('wise_profile'));
                $sameProfile = $authoritative->get('type') === $profile->get('type')
                    && $authoritative->get('principal_id') === $profile->get('principal_id')
                    && $authoritative->get('tenant_id') === $profile->get('tenant_id');
                if ($sameProfile) {
                    $roles = (array) $authoritative->get('roles');
                }
            }

            $profile->set('roles', $roles);
            $refreshed->set($profileKey, $profile->toArray());
        }

        return $refreshed->toArray();
    }

    private function activatedAddOns(): ?IActivatedAddOnsQuery
    {
        if ($this->activatedAddOns !== null) {
            return $this->activatedAddOns;
        }

        $resolved = $this->queryResolver === null
            ? \App::makeInstance(IActivatedAddOnsQuery::class)
            : ($this->queryResolver)();
        if ($resolved instanceof IActivatedAddOnsQuery) {
            $this->activatedAddOns = $resolved;
        }

        return $this->activatedAddOns;
    }
}
