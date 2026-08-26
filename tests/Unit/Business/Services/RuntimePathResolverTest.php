<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\RuntimePathResolver;
use BlueFission\Data\FileSystem;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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
            if ($path->isLink()) {
                unlink($path->getPathname());
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

    public function testActiveComposerProxyOwnsASeparatedSourceDirectoryNamedCore(): void
    {
        $package = $this->package($this->workspace . '/source/core');
        $host = $this->workspace . '/proxy-host';
        $autoloader = $host . '/vendor/autoload.php';
        $this->autoload($autoloader);

        $resolver = RuntimePathResolver::discover($package, null, $autoloader);

        $this->assertSame(realpath($autoloader), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testWindowsLegacyCoreInstallNameIsCaseInsensitive(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows legacy path comparison is platform-specific.');
        }

        $host = $this->workspace . '/case-host';
        $package = $this->package($host . '/CORE');
        $this->autoload($host . '/vendor/autoload.php');

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($host . '/vendor/autoload.php'), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testSymlinkedCoreInstallPreservesHostAutoloaderDiscovery(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('symlink')) {
            $this->markTestSkipped('Directory symlinks are unavailable in this environment.');
        }

        $source = $this->package($this->workspace . '/source/opus');
        $host = $this->workspace . '/linked-host';
        mkdir($host, 0777, true);
        $this->autoload($host . '/vendor/autoload.php');
        $link = $host . '/core';
        if (!@symlink($source, $link)) {
            $this->markTestSkipped('Directory symlinks cannot be created in this environment.');
        }

        $resolver = RuntimePathResolver::discover($link);

        $this->assertSame(realpath($source), $resolver->packageRoot());
        $this->assertSame(realpath($host . '/vendor/autoload.php'), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testEntrypointPathPreservesTheLexicalPackageInstallRoot(): void
    {
        $host = $this->workspace . '/linked-host';
        mkdir($host, 0777, true);
        $expected = realpath($host) . DIRECTORY_SEPARATOR . 'core';

        $this->assertSame(
            $expected,
            RuntimePathResolver::packageInstallRootFromEntrypoint(
                $host . '/core/public/index.php'
            )
        );
        $this->assertSame(
            $expected,
            RuntimePathResolver::packageInstallRootFromEntrypoint(
                $host . '/core/bin/opus-addon.php'
            )
        );
        $this->assertNull(
            RuntimePathResolver::packageInstallRootFromEntrypoint($host . '/vendor/bin/other-command')
        );

        $relative = RuntimePathResolver::packageInstallRootFromEntrypoint('core/bin/opus-addon.php');
        $this->assertSame(realpath(getcwd()) . DIRECTORY_SEPARATOR . 'core', $relative);
    }

    public function testEntrypointPackageRootMustContainTheSharedRuntimeBootstrap(): void
    {
        $host = $this->workspace . '/file-link-host';
        $package = $this->package($host . '/core');
        mkdir($package . '/common/bootstrap', 0777, true);
        file_put_contents($package . '/common/bootstrap/runtime.php', '<?php return true;');

        $inferredFromFileLink = RuntimePathResolver::packageInstallRootFromEntrypoint(
            $host . '/public/index.php'
        );

        $this->assertSame(realpath($host), $inferredFromFileLink);
        $this->assertFalse(RuntimePathResolver::isPackageInstallRoot($inferredFromFileLink));
        $this->assertTrue(RuntimePathResolver::isPackageInstallRoot($package));
    }

    public function testConfiguredComposerVendorDirectoryProvidesTheHostAutoloader(): void
    {
        $host = $this->workspace . '/custom-vendor-host';
        $package = $this->package($host . '/core');
        file_put_contents($host . '/composer.json', '{"config":{"vendor-dir":"deps"}}');
        $this->autoload($host . '/deps/autoload.php');

        $resolver = RuntimePathResolver::discover($package, $host);

        $this->assertSame(realpath($host . '/deps/autoload.php'), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testCoreLayoutUsesTheEnvironmentSelectedVendorDirectory(): void
    {
        $host = $this->workspace . '/environment-core-host';
        $package = $this->package($host . '/core');
        file_put_contents($host . '/composer.json', '{}');
        $autoload = $host . '/deps/autoload.php';
        $this->autoload($autoload);
        $previous = getenv('COMPOSER_VENDOR_DIR');
        putenv('COMPOSER_VENDOR_DIR=deps');

        try {
            $resolver = RuntimePathResolver::discover($package);

            $this->assertSame(realpath($autoload), $resolver->autoloadPath());
            $this->assertSame(realpath($host), $resolver->hostRoot());
        } finally {
            $previous === false
                ? putenv('COMPOSER_VENDOR_DIR')
                : putenv('COMPOSER_VENDOR_DIR=' . $previous);
        }
    }

    public function testNestedComposerVendorDirectoryRetainsTheInferredHostRoot(): void
    {
        $host = $this->workspace . '/nested-vendor-host';
        $package = $this->package($host . '/core');
        file_put_contents($host . '/composer.json', '{"config":{"vendor-dir":"build/deps"}}');
        $this->autoload($host . '/build/deps/autoload.php');

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($host . '/build/deps/autoload.php'), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testPackageInsideNestedCustomVendorDirectoryResolvesTheComposerHost(): void
    {
        $host = $this->workspace . '/nested-package-host';
        $package = $this->package($host . '/build/deps/bluefission/opus');
        file_put_contents($host . '/composer.json', '{"config":{"vendor-dir":"build/deps"}}');
        $autoload = $host . '/build/deps/autoload.php';
        $this->autoload($autoload);

        $resolver = RuntimePathResolver::discover($package, activeAutoloader: $autoload);

        $this->assertSame(realpath($autoload), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testDirectEntrypointInsideCustomVendorDirectoryResolvesTheComposerHost(): void
    {
        $host = $this->workspace . '/direct-custom-vendor-host';
        $package = $this->package($host . '/deps/bluefission/opus');
        file_put_contents($host . '/composer.json', '{"config":{"vendor-dir":"deps"}}');
        $autoload = $host . '/deps/autoload.php';
        $this->autoload($autoload);

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($autoload), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testEnvironmentSelectedVendorDirectoryResolvesByItsOwningAutoloader(): void
    {
        $host = $this->workspace . '/environment-vendor-host';
        $package = $this->package($host . '/deps/bluefission/opus');
        file_put_contents($host . '/composer.json', '{}');
        $autoload = $host . '/deps/autoload.php';
        $this->autoload($autoload);

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($autoload), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testConfiguredVendorDirectoryNormalizesDotSegmentsWithoutLosingItsHost(): void
    {
        $host = $this->workspace . '/dot-segment-vendor-host';
        $package = $this->package($host . '/deps/bluefission/opus');
        file_put_contents($host . '/composer.json', '{"config":{"vendor-dir":"./deps"}}');
        $autoload = $host . '/deps/autoload.php';
        $this->autoload($autoload);

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($autoload), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testConfiguredVendorDirectoryDoesNotCaptureAnUnrelatedNestedCheckout(): void
    {
        $host = $this->workspace . '/parent-composer-host';
        $package = $this->package($host . '/tools/opus');
        file_put_contents($host . '/composer.json', '{"config":{"vendor-dir":"deps"}}');
        $this->autoload($host . '/deps/autoload.php');
        $this->autoload($package . '/vendor/autoload.php');

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($package . '/vendor/autoload.php'), $resolver->autoloadPath());
        $this->assertSame(realpath($package), $resolver->hostRoot());
    }

    public function testSymlinkedConfiguredVendorRetainsLexicalHostOwnership(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('symlink')) {
            $this->markTestSkipped('Directory symlinks are unavailable in this environment.');
        }

        $sharedVendor = $this->workspace . '/shared/deps';
        $package = $this->package($sharedVendor . '/bluefission/opus');
        $this->autoload($sharedVendor . '/autoload.php');
        $host = $this->workspace . '/linked-vendor-host';
        mkdir($host, 0777, true);
        file_put_contents($host . '/composer.json', '{"config":{"vendor-dir":"deps"}}');
        if (!@symlink($sharedVendor, $host . '/deps')) {
            $this->markTestSkipped('Directory symlinks cannot be created in this environment.');
        }
        $lexicalPackage = $host . '/deps/bluefission/opus';
        $lexicalAutoloader = $host . '/deps/autoload.php';

        $direct = RuntimePathResolver::discover($lexicalPackage);
        $proxied = RuntimePathResolver::discover($package, activeAutoloader: $lexicalAutoloader);

        $this->assertSame(realpath($lexicalAutoloader), $direct->autoloadPath());
        $this->assertSame(realpath($host), $direct->hostRoot());
        $this->assertSame(realpath($host), $proxied->hostRoot());
    }

    public function testSymlinkedDefaultVendorRetainsLexicalHostOwnership(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('symlink')) {
            $this->markTestSkipped('Directory symlinks are unavailable in this environment.');
        }

        $sharedVendor = $this->workspace . '/shared-default/vendor';
        $package = $this->package($sharedVendor . '/bluefission/opus');
        $this->autoload($sharedVendor . '/autoload.php');
        $host = $this->workspace . '/default-vendor-host';
        mkdir($host, 0777, true);
        if (!@symlink($sharedVendor, $host . '/vendor')) {
            $this->markTestSkipped('Directory symlinks cannot be created in this environment.');
        }
        $lexicalPackage = $host . '/vendor/bluefission/opus';
        $lexicalAutoloader = $host . '/vendor/autoload.php';

        $direct = RuntimePathResolver::discover($lexicalPackage);
        $proxied = RuntimePathResolver::discover($package, activeAutoloader: $lexicalAutoloader);

        $this->assertSame(realpath($host), $direct->hostRoot());
        $this->assertSame(realpath($host), $proxied->hostRoot());
    }

    public function testLegacyCoreBesideASymlinkedDefaultVendorRetainsLexicalHostOwnership(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('symlink')) {
            $this->markTestSkipped('Directory symlinks are unavailable in this environment.');
        }

        $sharedVendor = $this->workspace . '/shared-legacy/vendor';
        $this->autoload($sharedVendor . '/autoload.php');
        $host = $this->workspace . '/legacy-default-host';
        $package = $this->package($host . '/core');
        if (!@symlink($sharedVendor, $host . '/vendor')) {
            $this->markTestSkipped('Directory symlinks cannot be created in this environment.');
        }

        $resolver = RuntimePathResolver::discover($package);

        $this->assertSame(realpath($sharedVendor . '/autoload.php'), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testComposerProxyEntrypointRetainsItsLexicalAutoloaderPath(): void
    {
        $vendor = $this->workspace . '/proxy/deps';
        $this->autoload($vendor . '/autoload.php');
        mkdir($vendor . '/bin', 0777, true);
        file_put_contents($vendor . '/bin/opus-addon.php', '<?php');

        $this->assertSame(
            realpath($vendor) . DIRECTORY_SEPARATOR . 'autoload.php',
            RuntimePathResolver::composerProxyAutoloaderFromEntrypoint(
                $vendor . '/bin/opus-addon.php'
            )
        );
    }

    public function testAncestorTraversalIncludesTheFilesystemRoot(): void
    {
        $root = DIRECTORY_SEPARATOR === '\\' ? 'C:\\' : DIRECTORY_SEPARATOR;
        $package = $root . 'deps' . DIRECTORY_SEPARATOR . 'bluefission' . DIRECTORY_SEPARATOR . 'opus';
        $method = new ReflectionMethod(RuntimePathResolver::class, 'ancestorDirectories');

        $ancestors = $method->invoke(null, $package);

        $this->assertIsArray($ancestors);
        $this->assertSame($root, $ancestors[array_key_last($ancestors)]);
    }

    public function testActiveNestedAutoloaderResolvesAHostOutsideTheCanonicalPackageAncestors(): void
    {
        $package = $this->package($this->workspace . '/source/opus');
        $host = $this->workspace . '/linked-package-host';
        mkdir($host, 0777, true);
        file_put_contents($host . '/composer.json', '{"config":{"vendor-dir":"build/deps"}}');
        $autoload = $host . '/build/deps/autoload.php';
        $this->autoload($autoload);

        $resolver = RuntimePathResolver::discover($package, activeAutoloader: $autoload);

        $this->assertSame(realpath($autoload), $resolver->autoloadPath());
        $this->assertSame(realpath($host), $resolver->hostRoot());
    }

    public function testFilesystemRootsRemainAbsoluteDuringNormalization(): void
    {
        $posix = RuntimePathResolver::discover(DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR);
        $drive = RuntimePathResolver::discover('C:\\', 'C:\\');
        $unc = RuntimePathResolver::discover(
            '\\\\server\\share\\vendor\\bluefission\\opus',
            '\\\\server\\share'
        );

        $this->assertSame(realpath(DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR, $posix->hostRoot());
        $this->assertSame('C:' . DIRECTORY_SEPARATOR, $drive->hostRoot());
        $this->assertSame(
            DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'share',
            $unc->hostRoot()
        );
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

    public function testResourceSymlinksCannotEscapeTheOwningRoot(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('symlink')) {
            $this->markTestSkipped('Directory symlinks are unavailable in this environment.');
        }

        $package = $this->package($this->workspace . '/package');
        $outside = $this->workspace . '/outside';
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/template.vibe', '<h1>Outside</h1>');
        mkdir($package . '/resource/themes', 0777, true);
        $link = $package . '/resource/themes/link';
        if (!@symlink($outside, $link)) {
            $this->markTestSkipped('Directory symlinks cannot be created in this environment.');
        }
        $resolver = RuntimePathResolver::discover($package);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must remain inside their owning root');

        $resolver->packageResourcePath('themes/link/template.vibe');
    }

    public function testDanglingResourceSymlinksAreRejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('symlink')) {
            $this->markTestSkipped('Directory symlinks are unavailable in this environment.');
        }

        $package = $this->package($this->workspace . '/package');
        mkdir($package . '/resource/themes', 0777, true);
        $link = $package . '/resource/themes/link';
        if (!@symlink($this->workspace . '/outside/missing', $link)) {
            $this->markTestSkipped('Dangling directory symlinks cannot be created in this environment.');
        }
        $resolver = RuntimePathResolver::discover($package);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must remain inside their owning root');

        $resolver->packageResourcePath('themes/link/template.vibe');
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
