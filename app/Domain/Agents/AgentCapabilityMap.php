<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use BlueFission\Arr;
use BlueFission\Str;

final class AgentCapabilityMap
{
    private int $version;
    private Str $owner;
    private Arr $agents;

    public function __construct(int $version, string $owner, array $agents)
    {
        $this->version = $version;
        $this->owner = Str::make($owner);
        $this->agents = Arr::make([]);

        Arr::make($agents)->each(function (array $descriptor, string $id): void {
            $this->agents->set($id, new AgentDescriptor($id, $this->owner(), $descriptor));
        });
    }

    public function version(): int
    {
        return $this->version;
    }

    public function owner(): string
    {
        return $this->owner->val();
    }

    public function agent(string $id): ?AgentDescriptor
    {
        $agent = $this->agents->get($id);

        return $agent instanceof AgentDescriptor ? $agent : null;
    }

    public function agents(): array
    {
        return $this->agents->toArray();
    }
}
