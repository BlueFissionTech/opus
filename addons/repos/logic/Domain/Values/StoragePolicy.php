<?php

declare(strict_types=1);

namespace AddOns\Repos\Domain\Values;

use InvalidArgumentException;

final readonly class StoragePolicy
{
    public function __construct(
        public int $maximumBytes = 65536,
        public int $retentionSeconds = 0,
        public bool $allowSharing = false
    ) {
        if ($maximumBytes < 1 || $maximumBytes > 65536
            || $retentionSeconds < 0 || $retentionSeconds > 315360000
        ) {
            throw new InvalidArgumentException('repos_policy_invalid');
        }
    }
}
