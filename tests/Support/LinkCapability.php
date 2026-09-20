<?php

declare(strict_types=1);

namespace Tests\Support;

use ErrorException;
use RuntimeException;

/** Scope warning handling to fixture creation, never the operation under test. */
final class LinkCapability
{
    public static function attempt(callable $createLink): bool
    {
        $unsupported = false;
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$unsupported): bool {
            if ($severity === E_WARNING
                && preg_match('/^(?:link|symlink)\(\): /', $message)
                && preg_match('/not supported|operation not permitted|privilege.*not held|improper link|cross-device link/i', $message)
            ) {
                $unsupported = true;
                return true;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $created = $createLink();
            if ($created !== true && !$unsupported) {
                throw new RuntimeException('Link fixture failed without an unsupported-capability diagnostic.');
            }
            return $created === true;
        } finally {
            restore_error_handler();
        }
    }
}
