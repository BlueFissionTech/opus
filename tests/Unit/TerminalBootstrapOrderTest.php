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

    public function testWebSocketWorkerLoadsSharedSettingsAndRestoresUnlimitedExecution(): void
    {
        $worker = FileSystem::fileContents(dirname(__DIR__, 2) . '/websocket-server.php');
        $this->assertIsString($worker);
        $autoload = Str::pos($worker, "require 'vendor/autoload.php';");
        $settings = Str::pos($worker, "require 'common/helpers/settings.php';");
        $unlimited = Str::pos($worker, 'set_time_limit(0);');

        $this->assertNotFalse($autoload);
        $this->assertNotFalse($settings);
        $this->assertNotFalse($unlimited);
        $this->assertLessThan($settings, $autoload);
        $this->assertLessThan($unlimited, $settings);
    }
}
