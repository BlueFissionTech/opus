<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\VibeGenerationService;
use BlueFission\Data\FileSystem;
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
        $service = new VibeGenerationService();
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
        $service = new VibeGenerationService();
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
}
