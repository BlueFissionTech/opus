<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Arr;
use BlueFission\BlueCore\Domain\AddOn\Queries\IActivatedAddOnsQuery;
use BlueFission\DevElation;
use BlueFission\Str;
use Throwable;

final class AgentCommandContextProvider
{
    public function __construct(private ?IActivatedAddOnsQuery $activatedAddOns = null)
    {
    }

    public function forActor(string $actorId, ?string $tenantId = null): array
    {
        $active = Arr::make([]);
        $states = Arr::make([]);

        try {
            $records = $this->activatedAddOns?->fetch() ?? [];
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
            'agent_id' => AgentScopedCommandProcessor::CENTRAL_AGENT,
            'active_addons' => $active->unique()->toArray(),
            'addon_states' => $states->toArray(),
            'capabilities' => [],
        ]);
        if (Str::isNotEmpty($actorId)) {
            $context->set('actor', ['id' => $actorId]);
        }
        if (Str::isNotEmpty((string) $tenantId)) {
            $context->set('tenant_id', $tenantId);
        }

        $filtered = DevElation::apply('opus.agent.command_context', $context->toArray());
        if (!Arr::is($filtered)) {
            return $context->toArray();
        }
        if (Str::isNotEmpty((string) $tenantId)
            && Arr::getPath((array) $filtered, 'tenant_id') !== $tenantId
        ) {
            return $context->toArray();
        }

        return (array) $filtered;
    }

    public function forContinuation(array $priorContext): array
    {
        $context = Arr::make($priorContext);
        $actorId = (string) Arr::getPath((array) $context->get('actor'), 'id', '');
        $tenantId = $context->get('tenant_id');
        $refreshed = Arr::make($this->forActor($actorId, Str::is($tenantId) ? $tenantId : null));

        return $refreshed->toArray();
    }
}
