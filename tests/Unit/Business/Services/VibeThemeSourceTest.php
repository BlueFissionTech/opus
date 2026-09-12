<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use BlueFission\Str;
use BlueFission\Vibrato\Reader;
use BlueFission\Vibrato\Validation\VibeSyntaxValidator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class VibeThemeSourceTest extends TestCase
{
    private const CLIENT_BINDINGS = [
        'active_users',
        'addon_name',
        'connection_state',
        'entry_name',
        'kpi_01_name',
        'kpi_01_value',
        'kpi_02_name',
        'kpi_02_value',
        'kpi_03_name',
        'kpi_03_value',
        'kpi_04_name',
        'kpi_04_value',
        'realname',
        'total_users',
        'user_churn',
        'user_realname',
    ];

    private string $markupDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markupDirectory = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'resource' . DIRECTORY_SEPARATOR . 'markup';
    }

    public function testEveryShippedThemeSourceUsesValidVibeSyntax(): void
    {
        $validator = new VibeSyntaxValidator();

        foreach ($this->vibeFiles() as $file) {
            $source = FileSystem::fileContents($file->getPathname());
            $this->assertIsString($source);

            $result = $validator->validate($source);
            $this->assertTrue(
                $result->isValid(),
                $file->getPathname() . ': ' . HTTP::jsonEncode($result->errors())
            );
        }
    }

    public function testEveryShippedThemeSourceRendersWithoutBackendExecution(): void
    {
        foreach ($this->vibeFiles() as $file) {
            if (basename($file->getPath()) === 'layouts') {
                $this->assertFileExists($file->getPathname());
                continue;
            }

            $themeDirectory = $this->themeDirectoryFor($file->getPathname());
            $reader = new Reader(null);
            $reader->setIncludePaths([
                'templates' => $themeDirectory,
                'modules' => $themeDirectory,
                'includes' => $themeDirectory,
            ]);
            $reader->inputFile($file->getPathname());
            $reader->run([
                'validate_syntax' => true,
                'run_backend' => false,
            ]);

            $output = $reader->output();
            $this->assertIsString($output, $file->getPathname());
            $this->assertStringNotContainsString('@include(', $output, $file->getPathname());
            $this->assertStringNotContainsString('@template(', $output, $file->getPathname());
            $this->assertStringNotContainsString('@output(', $output, $file->getPathname());
        }
    }

    public function testThemeSourcesHaveNoLegacyServerSyntaxOrMissingIncludes(): void
    {
        foreach ($this->vibeFiles() as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('@mod(', $source, $file->getPathname());

            preg_match_all('/(?<!\{)\{([A-Za-z_][A-Za-z0-9_.-]*)\}(?!\})/', $source, $legacyMatches);
            foreach ($legacyMatches[1] as $binding) {
                $this->assertContains($binding, self::CLIENT_BINDINGS, $file->getPathname());
            }

            preg_match_all('/@include\([\'\"]([^\'\"]+)[\'\"]\)/', $source, $includeMatches);
            foreach ($includeMatches[1] as $include) {
                $themeDirectory = $this->themeDirectoryFor($file->getPathname());
                $this->assertFileExists($themeDirectory . DIRECTORY_SEPARATOR . $include);
            }
        }
    }

    public function testThemeDirectoriesDoNotShipLegacyHtmlTemplateFiles(): void
    {
        $htmlFiles = [];
        foreach ($this->allThemeFiles() as $file) {
            if (Str::lower($file->getExtension()) === 'html') {
                $htmlFiles[] = $file->getPathname();
            }
        }

        $this->assertSame([], $htmlFiles);
    }

    /** @return list<SplFileInfo> */
    private function vibeFiles(): array
    {
        $files = [];
        foreach ($this->allThemeFiles() as $file) {
            if (Str::lower($file->getExtension()) === 'vibe') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return list<SplFileInfo> */
    private function allThemeFiles(): array
    {
        $files = [];
        foreach (['admin', 'default'] as $theme) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->markupDirectory . DIRECTORY_SEPARATOR . $theme)
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    private function themeDirectoryFor(string $path): string
    {
        foreach (['admin', 'default'] as $theme) {
            $themeDirectory = $this->markupDirectory . DIRECTORY_SEPARATOR . $theme;
            if (Str::startsWith($path, $themeDirectory . DIRECTORY_SEPARATOR)) {
                return $themeDirectory;
            }
        }

        throw new \LogicException("Theme directory not found for '{$path}'.");
    }
}
