<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Domain;

use BlueFission\Str;
use InvalidArgumentException;

/** Fixture payload is text data, never a command, credential or executable template. */
final readonly class RunRequest
{
    public function __construct(public string $workflow, public string $event, public string $subject,
        public int $attempt = 1)
    {
        Identifier::check($workflow);
        Identifier::check($event);
        if (Str::len($subject) > 4096 || $attempt < 1 || $attempt > 3) {
            throw new InvalidArgumentException('Invalid run request.');
        }
    }
}
