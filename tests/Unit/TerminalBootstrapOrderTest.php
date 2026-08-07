<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TerminalBootstrapOrderTest extends TestCase
{
    public function testComposerLoadsBeforeApplicationSettings(): void
    {
        $terminal = file_get_contents(dirname(__DIR__, 2) . '/terminal');
        $autoload = strpos($terminal, "require 'vendor/autoload.php';");
        $settings = strpos($terminal, "require 'common/helpers/settings.php';");

        $this->assertNotFalse($autoload);
        $this->assertNotFalse($settings);
        $this->assertLessThan($settings, $autoload);
    }
}
