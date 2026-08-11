<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Services\VibeThemeRenderer;
use BlueFission\BlueCore\Engine;
use BlueFission\Services\Application;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class TemplateHelperIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetApplications();

        parent::tearDown();
    }

    public function testBlueCoreTemplateHelperDelegatesToTheCanonicalVibeRenderer(): void
    {
        $this->resetApplications();

        $themeDirectory = dirname(__DIR__, 4)
            . DIRECTORY_SEPARATOR
            . 'resource'
            . DIRECTORY_SEPARATOR
            . 'markup'
            . DIRECTORY_SEPARATOR
            . 'default';
        $renderer = new VibeThemeRenderer(
            static fn (string $name): object => (object) [
                'name' => $name,
                'location' => $themeDirectory,
            ]
        );
        $engine = new Engine();
        $engine->delegate('template', $renderer);

        $output = template('default', 'login.vibe', ['url' => '/login?from=helper']);

        $this->assertStringContainsString('/login?from=helper', $output);
        $this->assertStringNotContainsString('@include(', $output);
    }

    private function resetApplications(): void
    {
        $instances = new ReflectionProperty(Application::class, '_instances');
        $instances->setValue(null, []);
    }
}
