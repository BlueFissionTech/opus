<?php

namespace Tests\Unit\Business\Services;

use App\Business\Services\ProjectPathResolver;
use PHPUnit\Framework\TestCase;

class ProjectPathResolverTest extends TestCase
{
    private string $applicationRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->applicationRoot = dirname(__DIR__, 4);
        $this->projectRoot = $this->applicationRoot . DIRECTORY_SEPARATOR . 'core';
    }

    public function testItResolvesAnExistingApplicationFile(): void
    {
        $this->assertSame(
            $this->applicationRoot . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            ProjectPathResolver::resolve('common/config/app.php', $this->applicationRoot, $this->projectRoot)
        );
    }

    public function testItResolvesMatchingWildcardsFromTheApplicationRoot(): void
    {
        $path = ProjectPathResolver::resolve(
            'common/config/*.php',
            $this->applicationRoot,
            $this->projectRoot
        );

        $this->assertSame(
            $this->applicationRoot . DIRECTORY_SEPARATOR . 'common' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '*.php',
            $path
        );
        $this->assertNotEmpty(glob($path));
    }

    public function testItPreservesTheProjectFallbackForMissingPaths(): void
    {
        $this->assertSame(
            $this->projectRoot . DIRECTORY_SEPARATOR . 'missing' . DIRECTORY_SEPARATOR . 'file.php',
            ProjectPathResolver::resolve('missing/file.php', $this->applicationRoot, $this->projectRoot)
        );
    }
}
