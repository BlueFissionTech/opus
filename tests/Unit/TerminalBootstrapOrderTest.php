<?php

declare(strict_types=1);

namespace Tests\Unit;

use BlueFission\Data\FileSystem;
use BlueFission\Str;
use PHPUnit\Framework\TestCase;

class TerminalBootstrapOrderTest extends TestCase
{
    public function testComposerLoadsBeforeApplicationSettings(): void
    {
        $terminal = FileSystem::fileContents(dirname(__DIR__, 2) . '/terminal');
        $this->assertIsString($terminal);
        $autoload = Str::pos($terminal, "require 'vendor/autoload.php';");
        $settings = Str::pos($terminal, "require 'common/helpers/settings.php';");

        $this->assertNotFalse($autoload);
        $this->assertNotFalse($settings);
        $this->assertLessThan($settings, $autoload);
    }
}
