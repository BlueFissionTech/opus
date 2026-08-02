<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ComposerMetadataTest extends TestCase
{
    public function testRatchetWebSocketTransportIsOptional(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../composer.json');

        $this->assertNotFalse($contents);

        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('cboden/ratchet', $composer['require'] ?? []);
        $this->assertArrayHasKey('cboden/ratchet', $composer['suggest'] ?? []);
    }
}
