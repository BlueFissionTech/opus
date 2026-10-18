<?php

declare(strict_types=1);

namespace App\Domain\Activity;

interface IActivityStore
{
    /** Commit one receipt or throw; an exception may represent uncertain commit. */
    public function append(ActivityEvent $event, ?string $approvalReference = null): void;

    public function approval(ActivityScope $scope, string $reference): ?ActivityEvent;

    /** @return array<array{sequence: int, event: ActivityEvent}> */
    public function read(ActivityScope $scope, int $after = 0, int $limit = 100, ?string $correlation = null): array;
}
