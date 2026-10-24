<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Domain;

/** Produced by host review, never deserialized from command input. */
final readonly class Approval
{
    public function __construct(public RunRequest $request, public string $application,
        public string $tenant, public string $actor, public bool $compensation = false) {}
}
