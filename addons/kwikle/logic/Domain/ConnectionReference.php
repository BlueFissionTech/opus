<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Domain;

final readonly class ConnectionReference
{
    public function __construct(public string $id, public string $client, public string $capability)
    {
        foreach ([$id, $client, $capability] as $value) { Identifier::check($value); }
    }
}
