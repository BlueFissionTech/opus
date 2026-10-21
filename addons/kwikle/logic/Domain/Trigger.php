<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Domain;

final readonly class Trigger
{
    public function __construct(public string $type = 'fixture.created') { Identifier::check($type); }
}
