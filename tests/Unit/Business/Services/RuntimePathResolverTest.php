<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\RuntimePathResolver;
use BlueFission\Data\FileSystem;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class RuntimePathResolverTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opus-runtime-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        if (!FileSystem::directoryExists($this->workspace)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workspace, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            if (!$path instanceof SplFileInfo) {
                continue;
            }
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->workspace);
    }

    public function testSourceCheckoutUsesItsPackageAutoloaderAndResources(): void
    {
        $parent = $this->workspace . '/parent';
        $package = $this->package($parent . '/artifacts/worktrees/source');
        $this->autoload($parent . '/vendor/autoload.php');
        $this->autoload($package . '/vendor/autoload.php');

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($package), $resolver->packageRoot());
        $this->assertSame(realpath($package), $resolver->hostRoot());
        $this->assertSame(realpath($package . '/vendor/autoload.php'), $resolver->autoloadPath());
        $this->assertSame(realpath($package . '/resource'), $resolver->packageResourceRoot());
        $this->assertSame(realpath($package . '/resource/markup/default'), $resolver->themeRoot('default'));
    }

    public function testComposerInstalledSourcePackageUsesTheHostAutoloader(): void
    {
        $host = $this->workspace . '/source-host';
        $package = $this->package($host . '/core');
        mkdir($package . '/.git', 0777, true);
        $this->autoload($host . '/vendor/autoload.php');

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($host . '/vendor/autoload.php'), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
        $this->assertSame(realpath($package . '/resource'), $resolver->packageResourceRoot());
    }

    public function testComposerInstalledDistributionPrefersHostOverPackageFallback(): void
    {
        $host = $this->workspace . '/dist-host';
        $package = $this->package($host . '/core');
        $this->autoload($host . '/vendor/autoload.php');
        $this->autoload($package . '/vendor/autoload.php');

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($host . '/vendor/autoload.php'), $resolver->autoloadPath());
        $this->assertDirectoryDoesNotExist($package . '/.git');
    }

    public function testStandaloneVendorInstallUsesTheHostAutoloader(): void
    {
        $host = $this->workspace . '/standalone-host';
        $package = $this->package($host . '/vendor/bluefission/opus');
        $this->autoload($host . '/vendor/autoload.php');

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($host . '/vendor/autoload.php'), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testActiveComposerProxyAutoloaderHasHighestPrecedence(): void
    {
        $package = $this->package($this->workspace . '/package');
        $this->autoload($package . '/vendor/autoload.php');
        $active = $this->workspace . '/active/vendor/autoload.php';
        $this->autoload($active);

        $resolver = RuntimePathResolver::discover($package, null, $active);

        $this->assertSame(realpath($active), $resolver->autoloadPath());
        $this->assertSame(realpath($this->workspace . '/active'), $resolver->hostRoot());
    }

    public function testHostThemeOverridesAreExplicitAndContained(): void
    {
        $host = $this->workspace . '/host';
        $package = $this->package($host . '/vendor/bluefission/opus');
        $this->autoload($host . '/vendor/autoload.php');
        mkdir($host . '/resource/themes/custom', 0777, true);
        $resolver = RuntimePathResolver::discover($package, $host);

        $this->assertSame(
            realpath($host . '/resource/themes/custom'),
            $resolver->themeRoot('default', 'themes/custom')
        );

        $this->expectException(\InvalidArgumentException::class);
        $resolver->packageResourcePath('../outside');
    }

    public function testAbsoluteResourcePathsAreRejected(): void
    {
        $package = $this->package($this->workspace . '/package');
        $resolver = RuntimePathResolver::discover($package);

        $this->expectException(\InvalidArgumentException::class);
        $resolver->packageResourcePath(DIRECTORY_SEPARATOR . 'outside');
    }

    private function package(string $root): string
    {
        mkdir($root . '/resource/markup/default', 0777, true);
        file_put_contents($root . '/resource/markup/default/login.vibe', '<h1>Login</h1>');

        return $root;
    }

    private function autoload(string $file): void
    {
        if (!FileSystem::directoryExists(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, "<?php return true;\n");
    }
}
