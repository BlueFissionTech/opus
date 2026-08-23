<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use BlueFission\Arr;
use BlueFission\Str;

final class ResolvedAgentToolMap
{
    private Str $agentId;
    private ?Str $tenantId;
    private Arr $tools;
    private Arr $versions;
    private Arr $decisions;

    public function __construct(
        string $agentId,
        ?string $tenantId,
        array $tools,
        array $versions,
        array $decisions
    ) {
        $this->agentId = Str::make($agentId);
        $this->tenantId = Str::isNotEmpty((string) $tenantId) ? Str::make((string) $tenantId) : null;
        $this->tools = Arr::make($tools)->unique()->sort();
        $this->versions = Arr::make($versions);
        $this->decisions = Arr::make($decisions);
    }

    public function agentId(): string
    {
        return $this->agentId->val();
    }

    public function tenantId(): ?string
    {
        return $this->tenantId?->val();
    }

    public function tools(): array
    {
        return $this->tools->toArray();
    }

    public function allows(string $tool): bool
    {
        return $this->tools->has($tool, true);
    }

    public function versions(): array
    {
        return $this->versions->toArray();
    }

    public function decisions(): array
    {
        return $this->decisions->toArray();
    }

    public function toArray(): array
    {
        return [
            'agent_id' => $this->agentId(),
            'tenant_id' => $this->tenantId(),
            'tools' => $this->tools(),
            'versions' => $this->versions(),
            'decisions' => $this->decisions(),
        ];
    }
}
