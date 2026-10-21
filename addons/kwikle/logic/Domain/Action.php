<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Domain;

final readonly class Action
{
    public function __construct(public ConnectionReference $connection, public string $type = 'fixture.capture')
    { Identifier::check($type); }
}
