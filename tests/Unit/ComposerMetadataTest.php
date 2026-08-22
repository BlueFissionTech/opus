<?php

declare(strict_types=1);

namespace Tests\Unit;

use BlueFission\Data\FileSystem;
use BlueFission\Net\HTTP;
use PHPUnit\Framework\TestCase;

class ComposerMetadataTest extends TestCase
{
    public function testRatchetWebSocketTransportIsOptional(): void
    {
        $contents = FileSystem::fileContents(__DIR__ . '/../../composer.json');

        $this->assertIsString($contents);

        $composer = HTTP::jsonDecode($contents, true, []);

        $this->assertIsArray($composer);
        $this->assertArrayNotHasKey('cboden/ratchet', $composer['require'] ?? []);
        $this->assertArrayHasKey('cboden/ratchet', $composer['suggest'] ?? []);
    }

    public function testAddOnCommandIsPublishedAsAComposerBinary(): void
    {
        $contents = FileSystem::fileContents(__DIR__ . '/../../composer.json');
        $composer = HTTP::jsonDecode((string) $contents, true, []);

        $this->assertContains('bin/opus-addon.php', $composer['bin'] ?? []);
        $this->assertFileExists(__DIR__ . '/../../bin/opus-addon.php');
    }
}
