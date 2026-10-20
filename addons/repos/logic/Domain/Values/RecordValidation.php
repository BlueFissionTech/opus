<?php

declare(strict_types=1);

namespace AddOns\Repos\Domain\Values;

use InvalidArgumentException;

/** Validate exact immutable inputs; global primitive filters must not attest to replacement values. */
final class RecordValidation
{
    public static function identifier(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException('repos_identifier_invalid');
        }
    }

    public static function name(string $value): void
    {
        if ($value === '.' || $value === '..' || $value === ''
            || strlen($value) > 160
            || preg_match('/^[^\x00-\x1f\x7f\/\\\\]+$/uD', $value) !== 1
        ) {
            throw new InvalidArgumentException('repos_name_invalid');
        }
    }

    public static function digest(string $value): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('repos_digest_invalid');
        }
    }
}
