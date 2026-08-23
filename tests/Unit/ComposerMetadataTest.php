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

    public function testWiseRuntimeProfilesHaveExplicitDependencyContracts(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = $this->readJson($root . '/composer.json');
        $required = $this->readJson($root . '/templates/composer/opus-root.json');
        $optional = $this->readJson($root . '/templates/composer/opus-root-optional-wise.json');

        $this->assertArrayNotHasKey('bluefission/wise', $composer['require'] ?? []);
        $this->assertSame('dev-main', $composer['require-dev']['bluefission/wise'] ?? null);
        $this->assertArrayHasKey('bluefission/wise', $composer['suggest'] ?? []);
        $this->assertSame('dev-main', $required['require']['bluefission/wise'] ?? null);
        $this->assertArrayNotHasKey('bluefission/wise', $optional['require'] ?? []);
        $this->assertArrayNotHasKey('bluefission/wise', $optional['repositories'] ?? []);
    }

    public function testWiseIsClassifiedAsDevelopmentOnlyInTheLock(): void
    {
        $lock = $this->readJson(dirname(__DIR__, 2) . '/composer.lock');
        $packages = array_column($lock['packages'] ?? [], 'name');
        $developmentPackages = array_column($lock['packages-dev'] ?? [], 'name');

        $this->assertNotContains('bluefission/wise', $packages);
        $this->assertContains('bluefission/wise', $developmentPackages);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = FileSystem::fileContents($path);

        $this->assertIsString($contents);

        $decoded = HTTP::jsonDecode($contents, true, []);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
