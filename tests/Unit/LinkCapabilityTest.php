<?php

declare(strict_types=1);

namespace Tests\Unit;

use ErrorException;
use PHPUnit\Framework\TestCase;
use Tests\Support\LinkCapability;

final class LinkCapabilityTest extends TestCase
{
    public function testUnsupportedProbeIsQuietAndRestoresThePreviousHandler(): void
    {
        $previous = static fn (): bool => false;
        set_error_handler($previous);
        $before = error_get_last();
        try {
            self::assertFalse(LinkCapability::attempt(static function (): bool {
                // Exercise the OS warning boundary without depending on host capabilities.
                $handler = set_error_handler(static fn (): bool => false);
                restore_error_handler();
                $handler(E_WARNING, 'link(): Improper link', __FILE__, __LINE__);
                return false;
            }));
            self::assertSame($before, error_get_last());
            $restored = set_error_handler(static fn (): bool => false);
            restore_error_handler();
            self::assertSame($previous, $restored);
        } finally { restore_error_handler(); }
    }

    public function testGenuineLinkFailureRemainsVisible(): void
    {
        $missing = sys_get_temp_dir() . '/opus-missing-' . bin2hex(random_bytes(8));
        $this->expectException(ErrorException::class);
        LinkCapability::attempt(static fn (): bool => link($missing, $missing . '-target'));
    }

    public function testUnexplainedFailureIsNotSilentlySkipped(): void
    {
        $this->expectException(\RuntimeException::class);
        LinkCapability::attempt(static fn (): bool => false);
    }
}
