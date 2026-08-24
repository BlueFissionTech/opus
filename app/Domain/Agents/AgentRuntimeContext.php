<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use BlueFission\Arr;
use BlueFission\Str;

final class AgentRuntimeContext
{
    private ?Str $tenantId;
    private Arr $actor;
    private Arr $activeAddOns;
    private Arr $lifecycleStates;
    private Arr $capabilities;
    private ?Str $correlationId;

    public function __construct(
        ?string $tenantId = null,
        array $actor = [],
        array $activeAddOns = [],
        array $lifecycleStates = [],
        array $capabilities = [],
        ?string $correlationId = null
    ) {
        $this->tenantId = Str::isNotEmpty((string) $tenantId) ? Str::make((string) $tenantId) : null;
        $this->actor = Arr::make($actor);
        $this->activeAddOns = Arr::make($activeAddOns)->unique();
        $this->lifecycleStates = Arr::make($lifecycleStates);
        $this->capabilities = Arr::make($capabilities)->unique();
        $this->correlationId = Str::isNotEmpty((string) $correlationId)
            ? Str::make((string) $correlationId)
            : null;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId?->val();
    }

    public function actor(): array
    {
        return $this->actor->toArray();
    }

    public function activeAddOns(): array
    {
        return $this->activeAddOns->toArray();
    }

    public function lifecycleStates(): array
    {
        return $this->lifecycleStates->toArray();
    }

    public function capabilities(): array
    {
        return $this->capabilities->toArray();
    }

    public function correlationId(): ?string
    {
        return $this->correlationId?->val();
    }

    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId(),
            'actor' => $this->actor(),
            'active_addons' => $this->activeAddOns(),
            'addon_states' => $this->lifecycleStates(),
            'capabilities' => $this->capabilities(),
            'correlation_id' => $this->correlationId(),
        ];
    }
}
