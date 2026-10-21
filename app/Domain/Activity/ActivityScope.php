<?php

declare(strict_types=1);

namespace App\Domain\Activity;

use BlueFission\Arr;
use BlueFission\Str;
use InvalidArgumentException;

final class ActivityScope
{
    public function __construct(
        public readonly string $applicationId,
        public readonly ?string $tenantId,
        public readonly string $actorId
    ) {
        foreach ([$applicationId, $actorId, ...($tenantId === null ? [] : [$tenantId])] as $id) {
            if (Str::isEmpty(Str::trim($id)) || Str::len($id) > 256 || Str::contains($id, "\0")) {
                throw new InvalidArgumentException('activity_scope_invalid');
            }
        }
    }

    /** Context must come from the authenticated host, never the command payload. */
    public static function fromContext(string $applicationId, array $context): self
    {
        $actor = Arr::getPath($context, 'actor.id');
        $tenant = Arr::getPath($context, 'tenant_id');
        if (!Str::is($actor) || ($tenant !== null && !Str::is($tenant))) {
            throw new InvalidArgumentException('activity_scope_required');
        }
        return new self($applicationId, $tenant, $actor);
    }

    public function matches(self $scope): bool
    {
        return $this->applicationId === $scope->applicationId
            && $this->tenantId === $scope->tenantId && $this->actorId === $scope->actorId;
    }
}
