<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Domain;

final readonly class Workflow
{
    public function __construct(public string $id, public string $application, public string $tenant,
        public Trigger $trigger, public Action $action, public RetryPolicy $retry = new RetryPolicy())
    {
        foreach ([$id, $application, $tenant] as $value) { Identifier::check($value); }
    }
}
