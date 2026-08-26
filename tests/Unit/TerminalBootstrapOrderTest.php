<?php

declare(strict_types=1);

namespace Tests\Unit;

use BlueFission\Data\FileSystem;
use BlueFission\Str;
use PHPUnit\Framework\TestCase;

class TerminalBootstrapOrderTest extends TestCase
{
    /**
     * @dataProvider runtimeEntrypoints
     */
    public function testRuntimeBootstrapLoadsBeforeApplicationSettings(string $entrypoint): void
    {
        $source = FileSystem::fileContents(dirname(__DIR__, 2) . '/' . $entrypoint);
        $this->assertIsString($source);
        $bootstrap = Str::pos($source, '/common/bootstrap/runtime.php');
        $settings = Str::pos($source, '/common/helpers/settings.php');

        $this->assertNotFalse($bootstrap);
        $this->assertNotFalse($settings);
        $this->assertLessThan($settings, $bootstrap);
    }

    public function testAddOnCommandUsesTheSharedRuntimeBootstrap(): void
    {
        $source = FileSystem::fileContents(dirname(__DIR__, 2) . '/bin/opus-addon.php');

        $this->assertIsString($source);
        $this->assertTrue(Str::make($source)->contains('/common/bootstrap/runtime.php'));
        $this->assertFalse(Str::make($source)->contains("/vendor/autoload.php"));
    }

    public function testRuntimeBootstrapHonorsComposerProxyAutoloaders(): void
    {
        $source = FileSystem::fileContents(dirname(__DIR__, 2) . '/common/bootstrap/runtime.php');

        $this->assertIsString($source);
        $this->assertTrue(Str::make($source)->contains('_composer_autoload_path'));
        $this->assertTrue(Str::make($source)->contains('autoloadPath()'));
    }

    public function testRuntimeBootstrapPreservesDirectEntrypointInstallPaths(): void
    {
        $source = FileSystem::fileContents(dirname(__DIR__, 2) . '/common/bootstrap/runtime.php');

        $this->assertIsString($source);
        $this->assertTrue(Str::make($source)->contains("\$_SERVER['SCRIPT_FILENAME']"));
        $this->assertTrue(Str::make($source)->contains('packageInstallRootFromEntrypoint'));
        $this->assertTrue(Str::make($source)->contains('isPackageInstallRoot'));
    }

    public function testWebSocketWorkerRestoresUnlimitedExecutionTimeAfterSettings(): void
    {
        $source = FileSystem::fileContents(dirname(__DIR__, 2) . '/websocket-server.php');

        $this->assertIsString($source);
        $settings = Str::pos($source, '/common/helpers/settings.php');
        $unlimited = Str::pos($source, 'set_time_limit(0)');
        $this->assertNotFalse($settings);
        $this->assertNotFalse($unlimited);
        $this->assertGreaterThan($settings, $unlimited);
    }

    /**
     * @dataProvider loaderEntrypoints
     */
    public function testRuntimeLoadersDoNotDependOnTheWorkingDirectory(string $entrypoint): void
    {
        $source = FileSystem::fileContents(dirname(__DIR__, 2) . '/' . $entrypoint);

        $this->assertIsString($source);
        $this->assertFalse(Str::make($source)->contains('addPath(getcwd().DIRECTORY_SEPARATOR'));
        $this->assertFalse(Str::make($source)->contains('addPath(dirname(getcwd()))'));
        $this->assertTrue(Str::make($source)->contains('$runtimePaths->hostRoot()'));
        $this->assertTrue(Str::make($source)->contains('$runtimePaths->packageRoot()'));
    }

    public static function runtimeEntrypoints(): array
    {
        return [
            'web' => ['public/index.php'],
            'cli' => ['terminal'],
            'worker' => ['websocket-server.php'],
        ];
    }

    public static function loaderEntrypoints(): array
    {
        return [
            'web' => ['public/index.php'],
            'cli' => ['terminal'],
        ];
    }
}
