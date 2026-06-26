<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\VibratoGenerationService;
use PHPUnit\Framework\TestCase;

class VibratoGenerationServiceTest extends TestCase
{
    public function testItValidatesVibeSyntax(): void
    {
        $service = new VibratoGenerationService();

        $result = $service->validateSource('{#if ready}missing close');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testItRendersSourceThroughVibrato(): void
    {
        $service = new VibratoGenerationService();

        $result = $service->renderSource('Opus kernel: {$kernel}', [
            'kernel' => 'Wise',
        ]);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertSame('Opus kernel: Wise', trim($result['output']));
        $this->assertSame('Wise', $result['variables']['kernel'] ?? null);
    }

    public function testItWritesRenderedFilesInsideWorkspace(): void
    {
        $service = new VibratoGenerationService();
        $source = tempnam(sys_get_temp_dir(), 'opus-vibe-');
        $this->assertIsString($source);
        file_put_contents($source, 'Addon agent: {$agent}');

        $target = 'tests/tmp/vibrato-generation-test.txt';
        if (is_file($target)) {
            unlink($target);
        }

        $result = $service->writeRenderedFile($source, $target, [
            'agent' => 'ready',
        ]);

        $this->assertTrue($result['valid'], json_encode($result['errors']));
        $this->assertFileExists($target);
        $this->assertSame('Addon agent: ready', trim((string)file_get_contents($target)));

        unlink($source);
        unlink($target);
        rmdir(dirname($target));
    }
}
