<?php

declare(strict_types=1);

namespace App\Business\Services;

use BlueFission\Val;
use RuntimeException;

final class WiseResourceClassResolver
{
    public static function resolve(string $canonicalClass, ?string $legacyClass = null): string
    {
        if (class_exists($canonicalClass, false)) {
            return $canonicalClass;
        }

        if (Val::isNotNull($legacyClass) && class_exists($legacyClass, false)) {
            return $legacyClass;
        }

        if (class_exists($canonicalClass)) {
            return $canonicalClass;
        }

        if (Val::isNotNull($legacyClass) && class_exists($legacyClass)) {
            return $legacyClass;
        }

        throw new RuntimeException("Wise resource class '{$canonicalClass}' is unavailable.");
    }
}
