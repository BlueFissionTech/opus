<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ApplicationRootCompatibilityTest extends TestCase
{
    public function testLegacyRootAliasUsesTheApplicationRoot(): void
    {
        require_once dirname(__DIR__, 2) . '/common/helpers/settings.php';

        $this->assertTrue(defined('APP_ROOT'));
        $this->assertTrue(defined('OPUS_ROOT'));
        $this->assertSame(APP_ROOT, OPUS_ROOT);
    }
}
