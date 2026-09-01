<?php

declare(strict_types=1);

namespace Tests\Unit;

use BlueFission\Arr;
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
        $this->assertSame('0.1.0-alpha.2', $composer['require-dev']['bluefission/wise'] ?? null);
        $this->assertSame('0.1.0-alpha.3', $composer['require']['bluefission/presence'] ?? null);
        $this->assertArrayHasKey('bluefission/wise', $composer['suggest'] ?? []);
        $this->assertSame('0.1.0-alpha.2', $required['require']['bluefission/wise'] ?? null);
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

    public function testWiseAndPresenceLocksUseReviewedAlphaReleases(): void
    {
        $lock = $this->readJson(dirname(__DIR__, 2) . '/composer.lock');
        $packages = Arr::make($lock['packages'] ?? [])->merge($lock['packages-dev'] ?? []);
        $byName = Arr::make();

        foreach ($packages as $package) {
            $package = Arr::make($package);
            $byName->set((string) $package->get('name'), $package);
        }

        $wise = $byName->get('bluefission/wise');
        $presence = $byName->get('bluefission/presence');
        $wiseReference = 'e55c68fd690c529989ccce62c74f2fe62e00edef';
        $presenceReference = '5c01dca824a2bccb2c899b59ab9587d21b715515';

        $this->assertInstanceOf(Arr::class, $wise);
        $this->assertInstanceOf(Arr::class, $presence);
        $this->assertSame('v0.1.0-alpha.2', $wise->get('version'));
        $this->assertSame($wiseReference, $wise->getPath('source.reference'));
        $this->assertSame($wiseReference, $wise->getPath('dist.reference'));
        $this->assertSame(
            "https://api.github.com/repos/bluefissiontech/wise/zipball/{$wiseReference}",
            $wise->getPath('dist.url')
        );
        $this->assertSame('v0.1.0-alpha.3', $presence->get('version'));
        $this->assertSame($presenceReference, $presence->getPath('source.reference'));
        $this->assertSame($presenceReference, $presence->getPath('dist.reference'));
        $this->assertSame(
            "https://api.github.com/repos/bluefissiontech/presence/zipball/{$presenceReference}",
            $presence->getPath('dist.url')
        );
    }

    public function testAddOnCommandIsPublishedAsAComposerBinary(): void
    {
        $composer = $this->readJson(__DIR__ . '/../../composer.json');

        $this->assertContains('bin/opus-addon.php', $composer['bin'] ?? []);
        $this->assertFileExists(__DIR__ . '/../../bin/opus-addon.php');
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
