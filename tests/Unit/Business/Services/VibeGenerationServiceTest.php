<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\VibeGenerationService;
use BlueFission\Data\FileSystem;
use BlueFission\Str;
use PHPUnit\Framework\TestCase;

class VibeGenerationServiceTest extends TestCase
{
    public function testItValidatesVibeSyntax(): void
    {
        $service = new VibeGenerationService();

        $result = $service->validateSource('{#if ready}missing close');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testItRendersSourceThroughVibrato(): void
    {
        $service = new VibeGenerationService();

        $result = $service->renderSource('Opus kernel: {$kernel}', [
            'kernel' => 'Wise',
        ]);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('Opus kernel: Wise', trim($result['output']));
        $this->assertSame('Wise', $result['variables']['kernel'] ?? null);
    }

    public function testItWritesRenderedFilesInsideWorkspace(): void
    {
        $service = $this->workspaceService();
        $source = $this->writeTempSource('Add-on agent: {$agent}');

        $target = 'tests/tmp/vibe-generation-test.txt';
        if (is_file($target)) {
            unlink($target);
        }

        $result = $service->writeRenderedFile($source, $target, [
            'agent' => 'ready',
        ]);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertTrue(FileSystem::fileExists($target));
        $this->assertSame('Add-on agent: ready', trim((string) FileSystem::fileContents($target)));

        unlink($source);
        unlink($target);
        rmdir(dirname($target));
    }

    public function testItRejectsRenderedFilesOutsideWorkspace(): void
    {
        $service = $this->workspaceService();
        $source = $this->writeTempSource('Blocked output');
        $target = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'opus-vibe-outside-' . getmypid() . '.txt';

        if (FileSystem::fileExists($target)) {
            unlink($target);
        }

        $result = $service->writeRenderedFile($source, $target);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('inside the application workspace', $result['errors'][0]['message']);
        $this->assertFalse(FileSystem::fileExists($target));

        unlink($source);
    }

    public function testItRejectsParentDirectoryTraversal(): void
    {
        $service = $this->workspaceService();
        $source = $this->writeTempSource('Blocked traversal output');
        $target = 'tests' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
            . 'vibe-traversal-' . getmypid() . '.txt';

        $result = $service->writeRenderedFile($source, $target);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('parent directory traversal', $result['errors'][0]['message']);
        $this->assertFalse(FileSystem::fileExists('vibe-traversal-' . getmypid() . '.txt'));

        unlink($source);
    }

    public function testItReplacesHardLinkedTargetsWithoutMutatingTheLinkedFile(): void
    {
        if (!function_exists('link')) {
            $this->markTestSkipped('Hard links are unavailable in this environment.');
        }

        $service = $this->workspaceService();
        $source = $this->writeTempSource('Replacement content');
        $outside = $this->writeTempSource('Outside original');
        $target = 'tests' . DIRECTORY_SEPARATOR . 'vibe-hardlink-' . getmypid() . '.txt';

        if (FileSystem::fileExists($target)) {
            unlink($target);
        }
        if (!@link($outside, $target)) {
            unlink($source);
            unlink($outside);
            $this->markTestSkipped('Hard links are unavailable in this environment.');
        }

        $result = $service->writeRenderedFile($source, $target);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('Replacement content', FileSystem::fileContents($target));
        $this->assertSame('Outside original', FileSystem::fileContents($outside));

        unlink($source);
        unlink($target);
        unlink($outside);
    }

    public function testItAppliesConsumerReadableTargetPermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions are platform-specific.');
        }

        $service = $this->workspaceService();
        $source = $this->writeTempSource('Replacement content');
        $target = 'tests' . DIRECTORY_SEPARATOR . 'vibe-mode-' . getmypid() . '.txt';
        $targetFile = new FileSystem([
            'root' => dirname($target),
            'mode' => 'w',
            'filter' => 'file',
            'doNotConfirm' => true,
        ]);
        $targetFile->open((string) FileSystem::fileBasename($target))
            ->contents('Original content')
            ->write()
            ->close();
        chmod($target, 0640);

        $result = $service->writeRenderedFile($source, $target);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame(0644, fileperms($target) & 0777);

        unlink($source);
        unlink($target);
    }

    public function testItRejectsRenderedFilesThroughWorkspaceSymlinks(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('symlink')) {
            $this->markTestSkipped('Directory symlinks are unavailable in this environment.');
        }

        $service = $this->workspaceService();
        $source = $this->writeTempSource('Blocked symlink output');
        $workspaceDirectory = 'tests' . DIRECTORY_SEPARATOR . 'tmp';
        $outsideDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'opus-vibe-symlink-' . getmypid();
        $link = $workspaceDirectory . DIRECTORY_SEPARATOR . 'outside-' . getmypid();
        $outsideTarget = $outsideDirectory . DIRECTORY_SEPARATOR . 'escape.txt';
        $ownsWorkspaceDirectory = false;

        if (!FileSystem::directoryExists($workspaceDirectory)) {
            mkdir($workspaceDirectory, 0777, true);
            $ownsWorkspaceDirectory = true;
        }
        if (!FileSystem::directoryExists($outsideDirectory)) {
            mkdir($outsideDirectory, 0777, true);
        }

        if (!@symlink($outsideDirectory, $link)) {
            unlink($source);
            rmdir($outsideDirectory);
            $this->markTestSkipped('Directory symlinks are unavailable in this environment.');
        }

        $result = $service->writeRenderedFile(
            $source,
            $link . DIRECTORY_SEPARATOR . 'escape.txt'
        );

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('inside the application workspace', $result['errors'][0]['message']);
        $this->assertFalse(FileSystem::fileExists($outsideTarget));

        unlink($source);
        unlink($link);
        rmdir($outsideDirectory);
        if ($ownsWorkspaceDirectory && FileSystem::directoryExists($workspaceDirectory)) {
            rmdir($workspaceDirectory);
        }
    }

    public function testItAnchorsRelativeOutputsToTheConfiguredWorkspace(): void
    {
        $workspace = dirname(__DIR__, 4);
        $service = $this->workspaceService();
        $source = $this->writeTempSource('Stable workspace output');
        $relativeDirectory = 'tests' . DIRECTORY_SEPARATOR . 'tmp-workspace-' . getmypid();
        $relativeTarget = $relativeDirectory . DIRECTORY_SEPARATOR . 'output.txt';
        $expectedTarget = $workspace . DIRECTORY_SEPARATOR . $relativeTarget;
        $originalDirectory = getcwd();
        $this->assertIsString($originalDirectory);

        try {
            chdir(sys_get_temp_dir());
            $result = $service->writeRenderedFile($source, $relativeTarget);
        } finally {
            chdir($originalDirectory);
        }

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame($expectedTarget, $result['path']);
        $this->assertTrue(FileSystem::fileExists($expectedTarget));

        unlink($source);
        unlink($expectedTarget);
        rmdir(dirname($expectedTarget));
    }

    public function testItComparesWindowsWorkspacePathsWithoutCaseSensitivity(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows path comparison is platform-specific.');
        }

        $workspace = dirname(__DIR__, 4);
        $service = $this->workspaceService();
        $source = $this->writeTempSource('Case-insensitive workspace output');
        $directory = $workspace . DIRECTORY_SEPARATOR . 'tests'
            . DIRECTORY_SEPARATOR . 'tmp-case-' . getmypid();
        $target = Str::lower($directory . DIRECTORY_SEPARATOR . 'output.txt');

        $result = $service->writeRenderedFile($source, $target);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertTrue(FileSystem::fileExists($target));

        unlink($source);
        unlink($target);
        rmdir($directory);
    }

    public function testItPreservesUncPrefixesDuringNormalization(): void
    {
        $service = $this->workspaceService();
        $normalized = (fn (string $path): string => $this->normalizePath($path))->call(
            $service,
            '\\\\server\\share\\opus\\output.php'
        );

        $this->assertSame('//server/share/opus/output.php', $normalized);
    }

    private function writeTempSource(string $contents): string
    {
        $source = tempnam(sys_get_temp_dir(), 'opus-vibe-');
        $this->assertIsString($source);
        $sourceFile = new FileSystem([
            'root' => dirname($source),
            'mode' => 'w',
            'filter' => 'file',
            'doNotConfirm' => true,
        ]);
        $sourceFile->open((string) FileSystem::fileBasename($source))
            ->contents($contents)
            ->write()
            ->close();

        return $source;
    }

    private function workspaceService(): VibeGenerationService
    {
        return new VibeGenerationService(null, null, dirname(__DIR__, 4));
    }
}
