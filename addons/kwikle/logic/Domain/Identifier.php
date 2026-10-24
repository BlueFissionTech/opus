<?php
declare(strict_types=1);
namespace AddOns\Kwikle\Domain;

use BlueFission\Str;
use InvalidArgumentException;

class Identifier
{
    public static function check(string $value): void
    {
        if (Str::isEmpty(Str::trim($value)) || Str::len($value) > 256) {
            throw new InvalidArgumentException('Invalid identifier.');
        }
    }
}
