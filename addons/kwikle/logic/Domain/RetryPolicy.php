<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Domain;

use InvalidArgumentException;

final readonly class RetryPolicy
{
    public function __construct(public int $maxAttempts = 1)
    {
        if ($maxAttempts < 1 || $maxAttempts > 3) { throw new InvalidArgumentException('Invalid retry bound.'); }
    }
}
