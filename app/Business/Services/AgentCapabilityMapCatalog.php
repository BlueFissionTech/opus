<?php

declare(strict_types=1);

namespace App\Business\Services;

use App\Domain\Agents\AgentCapabilityMap;
use BlueFission\Arr;
use BlueFission\Str;

final class AgentCapabilityMapCatalog
{
    public function __construct(
        private string $addOnRoot,
        private ?AgentCapabilityMapLoader $loader = null
    ) {
        $this->loader ??= new AgentCapabilityMapLoader();
    }

    public function load(array $owners): array
    {
        $maps = Arr::make([]);
        Arr::make($owners)
            ->filter(fn ($owner): bool => Str::is($owner) && $this->validOwner((string) $owner))
            ->unique()
            ->each(function (string $owner) use ($maps): void {
                $map = $this->loader->load($this->path($owner), $owner);
                if ($map->owner() === $owner) {
                    $maps->set($owner, $map);
                }
            });

        return $maps->toArray();
    }

    private function validOwner(string $owner): bool
    {
        return Str::make($owner)->matches('/^[a-z0-9][a-z0-9_-]*$/');
    }

    private function path(string $owner): string
    {
        return Str::make($this->addOnRoot)
            ->trim('/\\')
            ->append(DIRECTORY_SEPARATOR)
            ->append($owner)
            ->append(DIRECTORY_SEPARATOR . 'mapping' . DIRECTORY_SEPARATOR . 'agents.php')
            ->val();
    }
}
